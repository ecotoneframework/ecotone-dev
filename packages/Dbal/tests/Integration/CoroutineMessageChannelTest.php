<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Attribute\DelayedRetry;
use Ecotone\Api\Attribute\ErrorChannel;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\MessageGateway;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Dbal\Attribute\CurrentConnection;
use Ecotone\Api\Dbal\Attribute\DbalTransaction;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\CoroutineMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ErrorHandlerConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\RetryTemplateBuilder;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\CollectedMessages;
use Ecotone\Api\Messaging\CollectedReleaseReason;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Messaging\GatheredReleaseReason;
use Ecotone\Test\CoroutineTesting;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Event;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineMessageChannelTest extends TestCase
{
    #[DataProvider('sending_boundaries')]
    public function test_handler_starts_after_the_sender_commits_and_sees_its_data(string $boundary): void
    {
        $this->assert_after_commit($boundary, 'mysql');
    }

    #[RequiresPhpExtension('swoole')]
    #[Group('swoole')]
    #[DataProvider('sending_boundaries')]
    public function test_handler_starts_after_the_sender_commits_and_sees_its_data_on_postgresql(string $boundary): void
    {
        $this->assert_after_commit($boundary, 'pgsql');
    }

    private function assert_after_commit(string $boundary, string $database): void
    {
        $factory = new DbalConnectionFactory(self::dsnOf($database));
        $connection = $factory->establishConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS coroutine_channel_orders');
        $connection->executeStatement('CREATE TABLE coroutine_channel_orders (id INTEGER PRIMARY KEY)');
        $handler = new class () {
            public Connection $senderConnection;
            public array $observed = [];
            public Coroutine\Channel $notified;

            public function __construct()
            {
                $this->notified = new Coroutine\Channel();
            }

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 1, pointcut: CommandBus::class . '||' . AsynchronousRunningEndpoint::class . '||' . MessageGateway::class . '||' . ConsoleCommand::class)]
            public function pause_before_commit(MethodInvocation $invocation): mixed
            {
                $result = $invocation->proceed();
                $nextTick = new Coroutine\Channel();
                Event::defer(static fn () => $nextTick->push(true));
                $nextTick->pop();
                return $result;
            }

            #[CommandHandler('order.place')]
            public function place(int $id, #[CurrentConnection] Connection $connection, #[Reference] CommandBus $bus): void
            {
                $this->senderConnection = $connection;
                $connection->insert('coroutine_channel_orders', ['id' => $id]);
                $bus->sendWithRouting('order.notify', $id);
            }

            #[CommandHandler('order.place.event')]
            public function place_and_publish(int $id, #[CurrentConnection] Connection $connection, #[Reference] EventBus $bus): void
            {
                $this->senderConnection = $connection;
                $connection->insert('coroutine_channel_orders', ['id' => $id]);
                $bus->publishWithRouting('order.placed', $id);
            }

            #[Asynchronous('async')]
            #[EventHandler('order.placed', endpointId: 'order.placed.endpoint')]
            public function notify_event(int $id, #[CurrentConnection] Connection $connection): void
            {
                $this->notify($id, $connection);
            }

            #[ConsoleCommand('order.console')]
            public function place_console(int $id, #[CurrentConnection] Connection $connection, #[Reference] CommandBus $bus): void
            {
                $this->place($id, $connection, $bus);
            }

            #[InternalHandler('order.gateway')]
            public function place_through_gateway(int $id, #[CurrentConnection] Connection $connection, #[Reference] CommandBus $bus): void
            {
                $this->place($id, $connection, $bus);
            }

            #[Asynchronous('senders')]
            #[CommandHandler('order.place.async', endpointId: 'order.place.async.endpoint')]
            public function place_asynchronously(int $id, #[CurrentConnection] Connection $connection, #[Reference] CommandBus $bus): void
            {
                $this->place($id, $connection, $bus);
            }

            #[Asynchronous('async')]
            #[CommandHandler('order.notify', endpointId: 'order.notify.endpoint')]
            public function notify(int $id, #[CurrentConnection] Connection $connection): void
            {
                $senderIsInTransaction = $this->senderConnection->isTransactionActive();
                $this->observed[] = [$senderIsInTransaction, (int) $connection->fetchOne('SELECT COUNT(*) FROM coroutine_channel_orders WHERE id = ?', [$id])];
                $this->notified->push(true);
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [CoroutineOrderGateway::class],
            [$handler, DbalConnectionFactory::class => $factory],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async'), SimpleMessageChannelBuilder::createQueueChannel('senders'), DbalConfiguration::createWithDefaults()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        CoroutineTesting::runAtOnce(static function () use ($ecotone, $boundary, $handler): void {
            if ($boundary === 'event') {
                $ecotone->sendCommandWithRouting('order.place.event', 1);
            } elseif ($boundary === 'console') {
                $ecotone->runConsoleCommand('order.console', ['id' => 1]);
            } elseif ($boundary === 'gateway') {
                $ecotone->getGateway(CoroutineOrderGateway::class)->place(1);
            } elseif ($boundary === 'asynchronous') {
                $ecotone->sendCommandWithRouting('order.place.async', 1);
                $ecotone->run('senders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
            } else {
                $ecotone->sendCommandWithRouting('order.place', 1);
            }
            $handler->notified->pop();
        });

        $this->assertSame([[false, 1]], $handler->observed);
    }

    public function test_failed_message_goes_to_dbal_dead_letter_and_replays_outside_a_coroutine(): void
    {
        $handler = new class () {
            public bool $fail = true;
            public array $handled = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload, #[Header('tenant')] string $tenant): void
            {
                if ($this->fail) {
                    throw new RuntimeException('handling failed');
                }
                $this->handled[] = [$payload, $tenant, Coroutine::getCid()];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::dsnOf('mysql'))],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async'), DbalConfiguration::createWithDefaults(), PollingMetadata::create('async')->withErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $deadLetter = $ecotone->getGateway(DeadLetterGateway::class);
        $deadLetter->deleteAll();

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload', metadata: ['tenant' => 'replayed-tenant']));

        $this->assertSame(1, $deadLetter->count());
        $handler->fail = false;
        $deadLetter->replayAll();
        $this->assertCount(1, $handler->handled);
        $this->assertSame(['payload', 'replayed-tenant'], array_slice($handler->handled[0], 0, 2));
        $this->assertLessThanOrEqual(0, $handler->handled[0][2]);
        $this->assertSame(0, $deadLetter->count());
    }

    public function test_replay_inside_a_coroutine_handles_the_message_in_the_replaying_coroutine_before_the_replay_call_returns(): void
    {
        $this->assert_replay_in_the_replaying_coroutine('mysql');
    }

    #[RequiresPhpExtension('swoole')]
    #[Group('swoole')]
    public function test_replay_inside_a_coroutine_handles_the_message_in_the_replaying_coroutine_before_the_replay_call_returns_on_postgresql(): void
    {
        $this->assert_replay_in_the_replaying_coroutine('pgsql');
    }

    private function assert_replay_in_the_replaying_coroutine(string $database): void
    {
        $handler = new class () {
            public bool $fail = true;
            public array $handled = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload, #[Header('tenant')] string $tenant): void
            {
                if ($this->fail) {
                    throw new RuntimeException('handling failed');
                }
                $this->handled[] = [$payload, $tenant, Coroutine::getCid()];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::dsnOf($database))],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async'), DbalConfiguration::createWithDefaults(), PollingMetadata::create('async')->withErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $deadLetter = $ecotone->getGateway(DeadLetterGateway::class);
        $deadLetter->deleteAll();
        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload', metadata: ['tenant' => 'replayed-tenant']));
        $handler->fail = false;
        $handledWhenTheReplayReturned = [];
        $replayedIn = 0;

        CoroutineTesting::runAtOnce(static function () use ($deadLetter, $handler, &$handledWhenTheReplayReturned, &$replayedIn): void {
            $replayedIn = Coroutine::getCid();
            $deadLetter->replayAll();
            $handledWhenTheReplayReturned = $handler->handled;
        });

        $this->assertSame([['payload', 'replayed-tenant', $replayedIn]], $handledWhenTheReplayReturned);
        $this->assertSame(0, $deadLetter->count());
    }

    public function test_replay_that_fails_again_is_back_in_the_dead_letter(): void
    {
        $handler = new class () {
            public int $attempts = 0;

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->attempts++;
                throw new RuntimeException('handling failed');
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::dsnOf('mysql'))],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async'), DbalConfiguration::createWithDefaults(), PollingMetadata::create('async')->withErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $deadLetter = $ecotone->getGateway(DeadLetterGateway::class);
        $deadLetter->deleteAll();
        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload'));

        $deadLetter->replay($deadLetter->list(10, 0)[0]->getMessageId());
        $attemptsAfterTheReplayOutsideACoroutine = $handler->attempts;
        $storedAfterTheReplayOutsideACoroutine = $deadLetter->count();
        CoroutineTesting::runAtOnce(static fn () => $deadLetter->replay($deadLetter->list(10, 0)[0]->getMessageId()));

        $this->assertSame(2, $attemptsAfterTheReplayOutsideACoroutine);
        $this->assertSame(1, $storedAfterTheReplayOutsideACoroutine);
        $this->assertSame(3, $handler->attempts);
        $this->assertSame(1, $deadLetter->count());
        $this->assertSame('payload', $deadLetter->show($deadLetter->list(10, 0)[0]->getMessageId())->getPayload());
    }

    public function test_replaying_all_replays_a_message_that_fails_again_once(): void
    {
        $handler = new class () {
            public int $attempts = 0;

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->attempts++;
                if ($this->attempts <= 4) {
                    throw new RuntimeException('handling failed');
                }
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::dsnOf('mysql'))],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async'), DbalConfiguration::createWithDefaults(), PollingMetadata::create('async')->withErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $deadLetter = $ecotone->getGateway(DeadLetterGateway::class);
        $deadLetter->deleteAll();
        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload'));

        $deadLetter->replayAll();

        $this->assertSame(2, $handler->attempts);
        $this->assertSame(1, $deadLetter->count());
    }

    public function test_replay_that_fails_again_on_an_endpoint_with_delayed_retries_goes_straight_back_to_the_dead_letter_with_the_handlers_failure(): void
    {
        $handler = new class () {
            public int $attempts = 0;

            #[Asynchronous('async', asynchronousExecution: [new DelayedRetry(initialDelayInMilliseconds: 1, maxRetries: 1, deadLetterChannel: DbalDeadLetterBuilder::STORE_CHANNEL)])]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->attempts++;
                throw new RuntimeException("handling {$payload} failed");
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::dsnOf('mysql'))],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async'), DbalConfiguration::createWithDefaults(), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $deadLetter = $ecotone->getGateway(DeadLetterGateway::class);
        $deadLetter->deleteAll();
        $failures = static function () use ($deadLetter): array {
            $failures = array_map(static fn (ErrorContext $failed): string => $failed->getMessage(), $deadLetter->list(10, 0));
            sort($failures);

            return $failures;
        };
        CoroutineTesting::runAtOnce(static function () use ($ecotone): void {
            $ecotone->sendCommandWithRouting('handle', 'first');
            $ecotone->sendCommandWithRouting('handle', 'second');
        });
        $attemptsBeforeTheReplays = $handler->attempts;

        $deadLetter->replayAll();
        $attemptsAfterTheReplayOutsideACoroutine = $handler->attempts;
        $failuresAfterTheReplayOutsideACoroutine = $failures();
        CoroutineTesting::runAtOnce(static fn () => $deadLetter->replayAll());

        $this->assertSame(4, $attemptsBeforeTheReplays);
        $this->assertSame(6, $attemptsAfterTheReplayOutsideACoroutine);
        $this->assertSame(['handling first failed', 'handling second failed'], $failuresAfterTheReplayOutsideACoroutine);
        $this->assertSame(8, $handler->attempts);
        $this->assertSame(['handling first failed', 'handling second failed'], $failures());
    }

    public function test_replay_that_fails_again_skips_the_delayed_retries_configured_behind_the_endpoints_error_channel(): void
    {
        $handler = new class () {
            public int $attempts = 0;

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->attempts++;
                throw new RuntimeException('handling failed');
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::dsnOf('mysql'))],
            extensionObjects: [
                CoroutineMessageChannelBuilder::create('async'),
                DbalConfiguration::createWithDefaults(),
                PollingMetadata::create('async')->withErrorChannelName('retried'),
                ErrorHandlerConfiguration::createWithDeadLetterChannel('retried', RetryTemplateBuilder::fixedBackOff(1)->maxRetries(1), DbalDeadLetterBuilder::STORE_CHANNEL),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $deadLetter = $ecotone->getGateway(DeadLetterGateway::class);
        $deadLetter->deleteAll();
        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload'));
        $attemptsBeforeTheReplay = $handler->attempts;

        $deadLetter->replayAll();

        $this->assertSame(2, $attemptsBeforeTheReplay);
        $this->assertSame(3, $handler->attempts);
        $this->assertSame(['handling failed'], array_map(static fn (ErrorContext $failed): string => $failed->getMessage(), $deadLetter->list(10, 0)));
    }

    public function test_replay_that_fails_again_on_an_endpoint_with_delayed_retries_and_no_dead_letter_channel_fails_with_the_handlers_failure_and_keeps_the_message(): void
    {
        $storing = new class () {
            #[Asynchronous('async', asynchronousExecution: [new ErrorChannel(DbalDeadLetterBuilder::STORE_CHANNEL)])]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                throw new RuntimeException('handling failed');
            }
        };
        $retrying = new class () {
            public int $attempts = 0;

            #[Asynchronous('async', asynchronousExecution: [new DelayedRetry(initialDelayInMilliseconds: 1, maxRetries: 1)])]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->attempts++;
                throw new RuntimeException('handling failed again');
            }
        };
        $beforeTheDeployment = EcotoneLite::bootstrapFlowTesting(
            [],
            [$storing, DbalConnectionFactory::class => new DbalConnectionFactory(self::dsnOf('mysql'))],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async'), DbalConfiguration::createWithDefaults(), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $beforeTheDeployment->getGateway(DeadLetterGateway::class)->deleteAll();
        CoroutineTesting::runAtOnce(static fn () => $beforeTheDeployment->sendCommandWithRouting('handle', 'payload'));
        $deadLetter = EcotoneLite::bootstrapFlowTesting(
            [],
            [$retrying, DbalConnectionFactory::class => new DbalConnectionFactory(self::dsnOf('mysql'))],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async'), DbalConfiguration::createWithDefaults(), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->getGateway(DeadLetterGateway::class);
        $stored = $deadLetter->list(10, 0)[0]->getMessageId();

        $failure = null;
        try {
            $deadLetter->replay($stored);
        } catch (RuntimeException $exception) {
            $failure = $exception->getMessage();
        }

        $this->assertSame('handling failed again', $failure);
        $this->assertSame(1, $retrying->attempts);
        $this->assertSame([$stored], array_map(static fn (ErrorContext $failed): string => $failed->getMessageId(), $deadLetter->list(10, 0)));
    }

    public function test_replay_that_fails_again_skips_the_delayed_retries_behind_the_handlers_error_channel(): void
    {
        $handler = new class () {
            public int $attempts = 0;

            #[Asynchronous('async', asynchronousExecution: [new ErrorChannel('retried')])]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->attempts++;
                throw new RuntimeException('handling failed');
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::dsnOf('mysql'))],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async'), DbalConfiguration::createWithDefaults(), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false), ErrorHandlerConfiguration::createWithDeadLetterChannel('retried', RetryTemplateBuilder::fixedBackOff(1)->maxRetries(1), DbalDeadLetterBuilder::STORE_CHANNEL)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $deadLetter = $ecotone->getGateway(DeadLetterGateway::class);
        $deadLetter->deleteAll();
        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload'));
        $attemptsBeforeTheReplay = $handler->attempts;

        $deadLetter->replayAll();

        $this->assertSame(2, $attemptsBeforeTheReplay);
        $this->assertSame(3, $handler->attempts);
        $this->assertSame(['handling failed'], array_map(static fn (ErrorContext $failed): string => $failed->getMessage(), $deadLetter->list(10, 0)));
    }

    public function test_concurrent_messages_keep_their_own_headers_and_transaction(): void
    {
        $factory = new DbalConnectionFactory(self::dsnOf('mysql'));
        $connection = $factory->establishConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS coroutine_channel_deliveries');
        $connection->executeStatement('CREATE TABLE coroutine_channel_deliveries (id INTEGER PRIMARY KEY, tenant VARCHAR(255))');
        $handler = new class () {
            public array $before = [];
            public array $after = [];
            private Coroutine\Channel $release;
            private int $started = 0;

            public function __construct()
            {
                $this->release = new Coroutine\Channel();
            }

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(array $payload, #[CurrentConnection] Connection $connection, #[Header('tenant')] string $tenant): void
            {
                $this->before[$payload['id']] = [$tenant, $connection->isTransactionActive(), spl_object_id($connection)];
                $connection->insert('coroutine_channel_deliveries', ['id' => $payload['id'], 'tenant' => $tenant]);
                $this->started++;
                if ($this->started === 2) {
                    $this->release->close();
                } else {
                    $this->release->pop();
                }
                $this->after[$payload['id']] = [$tenant, $connection->isTransactionActive(), spl_object_id($connection)];
                if ($payload['fail']) {
                    throw new RuntimeException('delivery failed');
                }
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler, DbalConnectionFactory::class => $factory],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async')->withCoroutines(2), DbalConfiguration::createWithDefaults(), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        CoroutineTesting::runAtOnce(static function () use ($ecotone): void {
            $ecotone->sendCommandWithRouting('handle', ['id' => 1, 'fail' => true], metadata: ['tenant' => 'refused']);
            $ecotone->sendCommandWithRouting('handle', ['id' => 2, 'fail' => false], metadata: ['tenant' => 'accepted']);
        });

        $this->assertSame($handler->before[1], $handler->after[1]);
        $this->assertSame($handler->before[2], $handler->after[2]);
        $this->assertSame(['refused', true], array_slice($handler->before[1], 0, 2));
        $this->assertSame(['accepted', true], array_slice($handler->before[2], 0, 2));
        $this->assertNotSame($handler->before[1][2], $handler->before[2][2]);
        $this->assertSame(['accepted'], $factory->establishConnection()->fetchFirstColumn('SELECT tenant FROM coroutine_channel_deliveries ORDER BY id'));
    }

    public function test_caught_inner_failure_commits_its_rows_and_releases_both_commands_messages_with_the_outer_command(): void
    {
        $factory = new DbalConnectionFactory(self::dsnOf('mysql'));
        $connection = $factory->establishConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS coroutine_channel_nested_orders');
        $connection->executeStatement('CREATE TABLE coroutine_channel_nested_orders (id INTEGER PRIMARY KEY)');
        $handler = new class () {
            public array $handled = [];
            public string $caught = '';

            #[CommandHandler('outer')]
            public function outer(int $id, #[CurrentConnection] Connection $connection, #[Reference] CommandBus $bus): void
            {
                $connection->insert('coroutine_channel_nested_orders', ['id' => $id]);
                $bus->sendWithRouting('notify', $id);
                try {
                    $bus->sendWithRouting('inner', $id + 1);
                } catch (RuntimeException $exception) {
                    $this->caught = $exception->getMessage();
                }
            }

            #[CommandHandler('inner')]
            public function inner(int $id, #[CurrentConnection] Connection $connection, #[Reference] CommandBus $bus): void
            {
                $connection->insert('coroutine_channel_nested_orders', ['id' => $id]);
                $bus->sendWithRouting('notify', $id);
                throw new RuntimeException('inner failed');
            }

            #[Asynchronous('async')]
            #[CommandHandler('notify', endpointId: 'notify.endpoint')]
            public function notify(int $id, #[CurrentConnection] Connection $connection): void
            {
                $this->handled[$id] = (int) $connection->fetchOne('SELECT COUNT(*) FROM coroutine_channel_nested_orders WHERE id = ?', [$id]);
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler, DbalConnectionFactory::class => $factory],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async'), DbalConfiguration::createWithDefaults(), InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('outer', 0));

        ksort($handler->handled);
        $this->assertSame('inner failed', $handler->caught);
        $this->assertSame([1, 1], $handler->handled);
        $this->assertSame([0, 1], array_map('intval', $factory->establishConnection()->fetchFirstColumn('SELECT id FROM coroutine_channel_nested_orders ORDER BY id')));
    }

    public function test_interleaved_senders_release_only_the_successful_senders_message(): void
    {
        $factory = new DbalConnectionFactory(self::dsnOf('mysql'));
        $connection = $factory->establishConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS coroutine_channel_senders');
        $connection->executeStatement('CREATE TABLE coroutine_channel_senders (id INTEGER PRIMARY KEY)');
        $handler = new class () {
            public Coroutine\Channel $firstHolding;
            public Coroutine\Channel $resumeFirst;
            public array $handled = [];

            public function __construct()
            {
                $this->firstHolding = new Coroutine\Channel();
                $this->resumeFirst = new Coroutine\Channel();
            }

            #[CommandHandler('send')]
            public function send(int $id, #[CurrentConnection] Connection $connection, #[Reference] CommandBus $bus): void
            {
                $connection->insert('coroutine_channel_senders', ['id' => $id]);
                $bus->sendWithRouting('handle', $id);
                if ($id === 1) {
                    $this->firstHolding->push(true);
                    $this->resumeFirst->pop();
                } else {
                    throw new RuntimeException('second sender failed');
                }
            }

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(int $id): void
            {
                $this->handled[] = $id;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler, DbalConnectionFactory::class => $factory],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async'), DbalConfiguration::createWithDefaults(), InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $failure = null;

        CoroutineTesting::runAtOnce(
            static fn () => $ecotone->sendCommandWithRouting('send', 1),
            static function () use ($handler, $ecotone, &$failure): void {
                $handler->firstHolding->pop();
                try {
                    $ecotone->sendCommandWithRouting('send', 2);
                } catch (RuntimeException $exception) {
                    $failure = $exception->getMessage();
                } finally {
                    $handler->resumeFirst->push(true);
                }
            },
        );

        $this->assertSame('second sender failed', $failure);
        $this->assertSame([1], $handler->handled);
        $this->assertSame([1], array_map('intval', $factory->establishConnection()->fetchFirstColumn('SELECT id FROM coroutine_channel_senders')));
    }

    public function test_deduplicated_handler_runs_once_for_the_same_deduplication_key(): void
    {
        $handler = new class () {
            public array $handled = [];

            #[Deduplicated('deduplication-key')]
            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'deduplicated.coroutine.endpoint')]
            public function handle(string $payload): void
            {
                $this->handled[] = $payload;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::dsnOf('mysql'))],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async')->withCoroutines(1), DbalConfiguration::createWithDefaults()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $key = bin2hex(random_bytes(16));

        CoroutineTesting::runAtOnce(static function () use ($ecotone, $key): void {
            $ecotone->sendCommandWithRouting('handle', 'payload', metadata: ['deduplication-key' => $key]);
            $ecotone->sendCommandWithRouting('handle', 'payload', metadata: ['deduplication-key' => $key]);
        });

        $this->assertSame(['payload'], $handler->handled);
    }

    public function test_each_message_uses_its_tenants_connection(): void
    {
        $a = new DbalConnectionFactory('sqlite:////tmp/ecotone_coroutine_channel_tenant_a.db');
        $b = new DbalConnectionFactory('sqlite:////tmp/ecotone_coroutine_channel_tenant_b.db');
        foreach ([$a, $b] as $factory) {
            $connection = $factory->establishConnection();
            $connection->executeStatement('DROP TABLE IF EXISTS coroutine_channel_tenant_orders');
            $connection->executeStatement('CREATE TABLE coroutine_channel_tenant_orders (id INTEGER PRIMARY KEY, tenant VARCHAR(255))');
        }
        $handler = new class () {
            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'tenant.coroutine.endpoint')]
            public function handle(int $id, #[Header('tenant')] string $tenant, #[CurrentConnection] Connection $connection): void
            {
                $connection->insert('coroutine_channel_tenant_orders', ['id' => $id, 'tenant' => $tenant]);
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler, 'tenant_a_connection' => $a, 'tenant_b_connection' => $b],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async'), DbalConfiguration::createWithDefaults()->withDeduplication(false), MultiTenantConfiguration::create(tenantHeaderName: 'tenant', tenantToConnectionMapping: ['a' => 'tenant_a_connection', 'b' => 'tenant_b_connection'])],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        CoroutineTesting::runAtOnce(static function () use ($ecotone): void {
            $ecotone->sendCommandWithRouting('handle', 1, metadata: ['tenant' => 'a']);
            $ecotone->sendCommandWithRouting('handle', 2, metadata: ['tenant' => 'b']);
        });

        $this->assertSame(['a'], $a->establishConnection()->fetchFirstColumn('SELECT tenant FROM coroutine_channel_tenant_orders'));
        $this->assertSame(['b'], $b->establishConnection()->fetchFirstColumn('SELECT tenant FROM coroutine_channel_tenant_orders'));
    }

    public function test_parts_of_a_split_command_handler_handled_on_the_channel_are_gathered_once_with_every_part(): void
    {
        $this->assert_split_parts_are_gathered('mysql');
    }

    #[RequiresPhpExtension('swoole')]
    #[Group('swoole')]
    public function test_parts_of_a_split_command_handler_handled_on_the_channel_are_gathered_once_with_every_part_on_postgresql(): void
    {
        $this->assert_split_parts_are_gathered('pgsql');
    }

    private function assert_split_parts_are_gathered(string $database): void
    {
        $handler = new class () {
            public array $gathered = [];
            public array $transactions = [];

            #[CommandHandler('import', endpointId: 'coroutine.import.handler', outputChannelName: 'import.row')]
            #[Splitter(gatherChannelName: 'import.summary')]
            public function import(array $rows): array
            {
                return $rows;
            }

            #[Asynchronous('rows')]
            #[InternalHandler('import.row', endpointId: 'coroutine.import.row.handler')]
            public function row(int $row, #[CurrentConnection] Connection $connection): int
            {
                $this->transactions[$row] = [$connection->isTransactionActive(), spl_object_id($connection)];
                $connection->fetchOne('SELECT 1');

                return $row * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('import.summary', endpointId: 'coroutine.import.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = [$parts->results, $parts->originalPayload, $parts->releaseReason];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::dsnOf($database))],
            extensionObjects: [CoroutineMessageChannelBuilder::create('rows'), CoroutineMessageChannelBuilder::create('summaries'), DbalConfiguration::createWithDefaults()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $rows = range(1, 8);

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('import', $rows));

        $this->assertSame([[array_combine($rows, array_map(static fn (int $row): int => $row * 2, $rows)), $rows, GatheredReleaseReason::ALL_PARTS_ACCOUNTED_FOR]], $handler->gathered);
        ksort($handler->transactions);
        $this->assertSame(array_fill(1, 8, true), array_map(static fn (array $transaction): bool => $transaction[0], $handler->transactions));
        $this->assertGreaterThan(1, count(array_unique(array_column($handler->transactions, 1))));
    }

    public function test_an_asynchronous_split_command_handler_and_its_parts_share_the_channel_and_are_gathered_on_a_channel_of_one_coroutine(): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[Asynchronous('imports')]
            #[CommandHandler('import', endpointId: 'coroutine.shared.import.handler', outputChannelName: 'import.row')]
            #[Splitter(gatherChannelName: 'import.summary')]
            public function import(array $rows): array
            {
                return $rows;
            }

            #[Asynchronous('imports')]
            #[InternalHandler('import.row', endpointId: 'coroutine.shared.import.row.handler')]
            public function row(int $row, #[CurrentConnection] Connection $connection): int
            {
                $connection->fetchOne('SELECT 1');

                return $row * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('import.summary', endpointId: 'coroutine.shared.import.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts->results;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::dsnOf('mysql'))],
            extensionObjects: [CoroutineMessageChannelBuilder::create('imports'), CoroutineMessageChannelBuilder::create('summaries')->withCoroutines(1), DbalConfiguration::createWithDefaults()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        CoroutineTesting::runAtOnce(
            static fn () => $ecotone->sendCommandWithRouting('import', range(1, 8)),
            static fn () => $ecotone->sendCommandWithRouting('import', range(11, 18)),
        );

        $this->assertEqualsCanonicalizing([array_combine(range(1, 8), range(2, 16, 2)), array_combine(range(1, 8), range(22, 36, 2))], $handler->gathered);
    }

    public function test_messages_sent_at_once_are_collected_on_the_channel_into_one_batch(): void
    {
        $this->assert_messages_are_collected('mysql');
    }

    #[RequiresPhpExtension('swoole')]
    #[Group('swoole')]
    public function test_messages_sent_at_once_are_collected_on_the_channel_into_one_batch_on_postgresql(): void
    {
        $this->assert_messages_are_collected('pgsql');
    }

    private function assert_messages_are_collected(string $database): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 8, groupBy: "headers['import']")]
            #[Asynchronous('search')]
            #[CommandHandler('product.index', endpointId: 'coroutine.product.index.handler')]
            public function index(CollectedMessages $changes, #[CurrentConnection] Connection $connection): void
            {
                $payloads = array_column($changes->messages, 'payload');
                sort($payloads);
                $this->batches[] = [$payloads, $changes->releaseReason, $connection->isTransactionActive()];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::dsnOf($database))],
            extensionObjects: [CoroutineMessageChannelBuilder::create('search'), DbalConfiguration::createWithDefaults()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $import = bin2hex(random_bytes(16));

        CoroutineTesting::runAtOnce(...array_map(static fn (int $product) => static fn () => $ecotone->sendCommandWithRouting('product.index', $product, metadata: ['import' => $import]), range(1, 8)));

        $this->assertSame([[range(1, 8), CollectedReleaseReason::MAX_SIZE_REACHED, true]], $handler->batches);
    }

    public function test_a_group_whose_gathered_handler_failed_on_the_channel_is_gathered_again_with_every_part(): void
    {
        $handler = new class () {
            public array $gathered = [];
            public int $attempts = 0;

            #[CommandHandler('import', endpointId: 'coroutine.import.handler', outputChannelName: 'import.row')]
            #[Splitter(gatherChannelName: 'import.summary')]
            public function import(array $rows): array
            {
                return $rows;
            }

            #[Asynchronous('rows')]
            #[InternalHandler('import.row', endpointId: 'coroutine.import.row.handler')]
            public function row(int $row): int
            {
                return $row * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('import.summary', endpointId: 'coroutine.import.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                if (++$this->attempts === 1) {
                    throw new RuntimeException('summary failed');
                }
                $this->gathered[] = [$parts->results, $parts->releaseReason];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::dsnOf('mysql'))],
            extensionObjects: [CoroutineMessageChannelBuilder::create('rows'), CoroutineMessageChannelBuilder::create('summaries'), DbalConfiguration::createWithDefaults()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('import', range(1, 8)));

        $this->assertSame(2, $handler->attempts);
        $this->assertCount(1, $handler->gathered);
        $this->assertEquals(array_combine(range(1, 8), range(2, 16, 2)), $handler->gathered[0][0]);
        $this->assertSame(GatheredReleaseReason::ALL_PARTS_ACCOUNTED_FOR, $handler->gathered[0][1]);
    }

    public function test_a_batch_whose_collected_handler_failed_on_the_channel_is_handled_again_with_every_message(): void
    {
        $handler = new class () {
            public array $batches = [];
            public int $attempts = 0;

            #[Collected(maxSize: 8, groupBy: "headers['import']")]
            #[Asynchronous('search')]
            #[CommandHandler('product.index', endpointId: 'coroutine.product.index.handler')]
            public function index(CollectedMessages $changes): void
            {
                if (++$this->attempts === 1) {
                    throw new RuntimeException('indexing failed');
                }
                $payloads = array_column($changes->messages, 'payload');
                sort($payloads);
                $this->batches[] = [$payloads, $changes->releaseReason];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::dsnOf('mysql'))],
            extensionObjects: [CoroutineMessageChannelBuilder::create('search'), DbalConfiguration::createWithDefaults()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $import = bin2hex(random_bytes(16));

        CoroutineTesting::runAtOnce(...array_map(static fn (int $product) => static fn () => $ecotone->sendCommandWithRouting('product.index', $product, metadata: ['import' => $import]), range(1, 8)));

        $this->assertSame(2, $handler->attempts);
        $this->assertSame([[range(1, 8), CollectedReleaseReason::MAX_SIZE_REACHED]], $handler->batches);
    }

    private static function dsnOf(string $database): string
    {
        foreach ([getenv('DATABASE_DSN'), getenv('SECONDARY_DATABASE_DSN')] as $dsn) {
            if (is_string($dsn) && str_starts_with($dsn, $database)) {
                return $dsn;
            }
        }
        throw new RuntimeException("The coroutine tests need a {$database} database in DATABASE_DSN or SECONDARY_DATABASE_DSN.");
    }

    public static function sending_boundaries(): array
    {
        return [['command_bus'], ['gateway'], ['asynchronous'], ['console'], ['event']];
    }
}

interface CoroutineOrderGateway
{
    #[DbalTransaction]
    #[MessageGateway('order.gateway')]
    public function place(int $id): void;
}
