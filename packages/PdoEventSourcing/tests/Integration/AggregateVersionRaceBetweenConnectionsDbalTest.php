<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Support\ConcurrencyException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class AggregateVersionRaceBetweenConnectionsDbalTest extends EventSourcingMessagingTestCase
{
    public const STREAM = 'aggregate_version_race';
    private const MILLISECONDS_THE_FIRST_TRANSACTION_STAYS_OPEN = 1000;

    public function test_two_connections_that_both_passed_the_version_check_record_the_version_once_because_the_unique_index_refuses_the_second(): void
    {
        if (self::getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite serialises writers with a database lock, so the second connection never reaches the unique index: it waits for the first connection\'s lock while holding its read of the version, and ends in LockWaitTimeoutException after PDO\'s 60 second busy timeout instead of a ConcurrencyException. That race is not decided by the index there, and is left unproved on SQLite.');
        }

        $firstConnection = self::getConnectionFactory();
        $first = $this->eventStoreOn($firstConnection, setUpTables: true);
        $first->appendTo(self::STREAM, [$this->ticketEvent('opened', 1)]);
        $signal = $this->signalFile();
        $outcome = $this->signalFile();

        $firstConnection->createContext()->getDbalConnection()->beginTransaction();
        $first->appendTo(self::STREAM, [$this->ticketEvent('renamed by the first', 2)], AppendCondition::forAggregate('ticket', 't-1', 1));

        $second = pcntl_fork();
        if ($second === 0) {
            $this->appendTheSameVersionOnASecondConnection($signal, $outcome);
        }

        $this->waitUntilWritten($signal);
        usleep(self::MILLISECONDS_THE_FIRST_TRANSACTION_STAYS_OPEN * 1000);
        $firstConnection->createContext()->getDbalConnection()->commit();
        pcntl_waitpid($second, $status);

        [$refusal, $waitedMilliseconds] = explode('|', file_get_contents($outcome));
        unlink($signal);
        unlink($outcome);
        self::assertSame(ConcurrencyException::class, $refusal);
        self::assertGreaterThanOrEqual(self::MILLISECONDS_THE_FIRST_TRANSACTION_STAYS_OPEN / 2, (int) $waitedMilliseconds);
        self::assertSame(
            ['opened', 'renamed by the first'],
            array_map(fn (Event $event) => $event->getPayload()['change'], iterator_to_array($first->load(self::STREAM, deserialize: false))),
        );
    }

    private function appendTheSameVersionOnASecondConnection(string $signal, string $outcome): never
    {
        $refusal = 'appended';
        $started = hrtime(true);
        try {
            $second = $this->eventStoreOn(new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@127.0.0.1:5432/ecotone'), setUpTables: false);
            file_put_contents($signal, 'ready');
            $started = hrtime(true);
            $second->appendTo(self::STREAM, [$this->ticketEvent('renamed by the second', 2)], AppendCondition::forAggregate('ticket', 't-1', 1));
        } catch (Throwable $failure) {
            $refusal = $failure::class;
        }
        file_put_contents($outcome, $refusal . '|' . (int) ((hrtime(true) - $started) / 1e6));

        posix_kill(posix_getpid(), SIGKILL);
        exit(1);
    }

    private function eventStoreOn(DbalConnectionFactory $connectionFactory, bool $setUpTables): EventStore
    {
        $stream = new #[Stream(AggregateVersionRaceBetweenConnectionsDbalTest::STREAM)] class () {
        };

        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [$stream::class],
            containerOrAvailableServices: [DbalConnectionFactory::class => $connectionFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([EventSourcingConfiguration::createWithDefaults()]),
            runForProductionEventStore: true,
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap($setUpTables),
        )->getGateway(EventStore::class);
    }

    private function ticketEvent(string $change, int $version): Event
    {
        return Event::createWithType('ticket.changed', ['change' => $change], [
            MessageHeaders::EVENT_AGGREGATE_TYPE => 'ticket',
            MessageHeaders::EVENT_AGGREGATE_ID => 't-1',
            MessageHeaders::EVENT_AGGREGATE_VERSION => $version,
        ]);
    }

    private function signalFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'ecotone_race_');
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
