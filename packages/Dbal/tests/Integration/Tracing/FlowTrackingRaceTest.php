<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Tracing;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\Tracing\FlowCompleted;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\Dbal\Tracing\OnFlowCompleted;
use Ecotone\Api\Dbal\Tracing\TracingGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Throwable;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class FlowTrackingRaceTest extends DbalMessagingTestCase
{
    private const MILLISECONDS_THE_FIRST_CONSUMER_STAYS_UNCOMMITTED = 1000;

    public function tearDown(): void
    {
        CommitHoldingConnection::$beforeCommit = null;
        parent::tearDown();
    }

    public static string $notificationsFile = '';

    public function test_two_consumers_finishing_the_same_flow_at_the_same_moment_notify_its_completion_once(): void
    {
        if ($this->getConnectionFactory()->createContext()->getDbalConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite serialises writers with a database lock, so the second consumer cannot run while the first one holds its transaction open. The race is left unproved on SQLite.');
        }

        $first = $this->bootstrap(new DbalConnectionFactory([
            'dsn' => self::dsn(),
            'connection' => ['wrapperClass' => CommitHoldingConnection::class],
        ]), setUpTables: true);
        $tracingId = '0af7651916cd43dd8448eb211c80319c';
        self::$notificationsFile = $this->signalFile();
        $first->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACING_ID => $tracingId]);
        $ready = $this->signalFile();
        $outcome = $this->signalFile();
        $second = null;

        CommitHoldingConnection::$beforeCommit = function () use ($ready, $outcome, &$second): void {
            CommitHoldingConnection::$beforeCommit = null;
            $second = pcntl_fork();
            if ($second === 0) {
                $this->consumeTheOtherDeliveryOnASecondConnection($ready, $outcome);
            }
            $this->waitUntilWritten($ready);
            usleep(self::MILLISECONDS_THE_FIRST_CONSUMER_STAYS_UNCOMMITTED * 1000);
        };
        $first->run('mail_channel', ExecutionPollingMetadata::createWithTestingSetup());
        pcntl_waitpid($second, $status);

        [$consumed, $waitedMilliseconds] = explode('|', file_get_contents($outcome));
        $notified = file_exists(self::$notificationsFile) ? file(self::$notificationsFile, FILE_IGNORE_NEW_LINES) : [];
        unlink($ready);
        unlink($outcome);
        @unlink(self::$notificationsFile);
        self::assertSame('consumed', $consumed);
        self::assertTrue($first->getGateway(TracingGateway::class)->isCompletedFor($tracingId));
        self::assertSame([$tracingId], $notified);
        self::assertGreaterThanOrEqual(self::MILLISECONDS_THE_FIRST_CONSUMER_STAYS_UNCOMMITTED / 2, (int) $waitedMilliseconds);
    }

    private function consumeTheOtherDeliveryOnASecondConnection(string $ready, string $outcome): never
    {
        $consumed = 'not consumed';
        $started = hrtime(true);
        try {
            $second = $this->bootstrap(new DbalConnectionFactory(self::dsn()), setUpTables: false);
            file_put_contents($ready, 'ready');
            $started = hrtime(true);
            $second->run('sms_channel', ExecutionPollingMetadata::createWithTestingSetup());
            $consumed = 'consumed';
        } catch (Throwable $failure) {
            $consumed = $failure::class . ': ' . $failure->getMessage();
        }
        file_put_contents($outcome, $consumed . '|' . (int) ((hrtime(true) - $started) / 1e6));

        posix_kill(posix_getpid(), SIGKILL);
        exit(1);
    }

    private function bootstrap(DbalConnectionFactory $connectionFactory, bool $setUpTables): FlowTestSupport
    {
        $users = new class () {
            #[CommandHandler('user.register', endpointId: 'user.registration')]
            public function register(string $userId, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('user.registered', $userId);
            }
        };
        $subscriber = new class () {
            #[OnFlowCompleted]
            public function whenCompleted(FlowCompleted $event): void
            {
                file_put_contents(FlowTrackingRaceTest::$notificationsFile, $event->tracingId . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        };
        $notifications = new class () {
            #[Asynchronous('mail_channel')]
            #[EventHandler('user.registered', endpointId: 'user.mailer')]
            public function mail(string $userId): void
            {
            }

            #[Asynchronous('sms_channel')]
            #[EventHandler('user.registered', endpointId: 'user.texter')]
            public function text(string $userId): void
            {
            }
        };

        return $this->bootstrapFlowTesting(
            classesToResolve: [$users::class, $notifications::class, $subscriber::class],
            containerOrAvailableServices: [$users, $notifications, $subscriber, DbalConnectionFactory::class => $connectionFactory],
            extensionObjects: [
                FlowTrackingConfiguration::createWithDefaults(),
                DbalBackedMessageChannelBuilder::create('mail_channel'),
                DbalBackedMessageChannelBuilder::create('sms_channel'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap($setUpTables),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private static function dsn(): string
    {
        return getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
    }

    private function signalFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'ecotone_flow_race_');
        unlink($file);

        return $file;
    }

    private function waitUntilWritten(string $file): void
    {
        $deadline = hrtime(true) + 30 * 1e9;
        while (! file_exists($file) && hrtime(true) < $deadline) {
            usleep(10_000);
        }
    }
}

final class CommitHoldingConnection extends Connection
{
    /** @var (Closure(): void)|null */
    public static ?Closure $beforeCommit = null;

    public function commit(): void
    {
        if (self::$beforeCommit !== null && $this->getTransactionNestingLevel() === 1) {
            (self::$beforeCommit)();
        }

        parent::commit();
    }
}
