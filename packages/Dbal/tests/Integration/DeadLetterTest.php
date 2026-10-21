<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Test\StaticMessageBuilder;
use Ecotone\Test\StubLogger;
use Ecotone\Messaging\Support\InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\DeadLetter\DoubleEventHandler\DoubleEventHandler;
use Test\Ecotone\Dbal\Fixture\DeadLetter\DoubleEventHandler\ExampleEvent;
use Test\Ecotone\Dbal\Fixture\DeadLetter\Example\ErrorConfigurationContext;
use Test\Ecotone\Dbal\Fixture\DeadLetter\Example\OrderGateway;
use Test\Ecotone\Dbal\Fixture\DeadLetter\Example\OrderService;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class DeadLetterTest extends DbalMessagingTestCase
{
    public function test_exception_handling_with_using_error_channel_right_away(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\Example',
        ], extensionObjects: [
            PollingMetadata::create('orderService')
                ->withExecutionTimeLimitInMilliseconds(1000)
                ->withHandledMessageLimit(1)
                ->withErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL),
        ], orderService: new OrderService(1));

        $gateway = $ecotone->getGateway(OrderGateway::class);

        $gateway->order('coffee');

        $ecotone->run('orderService');

        self::assertEquals(0, $gateway->getOrderAmount());

        $this->assertErrorMessageCount($ecotone, 1, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        $this->replayAllErrorMessages($ecotone);

        $this->assertErrorMessageCount($ecotone, 0, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        $ecotone->run('orderService');

        self::assertEquals(1, $gateway->getOrderAmount());
    }

    public function test_exception_handling_with_custom_handling_1_retry(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\Example',
            'Test\Ecotone\Dbal\Fixture\DeadLetter\CustomConfiguration',
        ]);

        $gateway = $ecotone->getGateway(OrderGateway::class);

        $gateway->order('coffee');

        $ecotone->run('orderService');

        self::assertEquals(0, $gateway->getOrderAmount());

        $ecotone->run('orderService');

        self::assertEquals(0, $gateway->getOrderAmount());

        $this->assertErrorMessageCount($ecotone, 1, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        $this->replayAllErrorMessages($ecotone);

        $this->assertErrorMessageCount($ecotone, 0, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        $ecotone->run('orderService');

        self::assertEquals(1, $gateway->getOrderAmount());
    }

    public function test_exception_handling_replaying_multiple_dead_messages(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\Example',
            'Test\Ecotone\Dbal\Fixture\DeadLetter\DeadLetterRightAway',
        ]);

        $gateway = $ecotone->getGateway(OrderGateway::class);

        $gateway->order('coffee');

        $ecotone->run('orderService');
        $ecotone->run('orderService');

        $gateway->order('coffee');

        $ecotone->run('orderService');
        $ecotone->run('orderService');

        $this->assertErrorMessageCount($ecotone, 2, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        $this->replayAllErrorMessagesById($ecotone);

        $this->assertErrorMessageCount($ecotone, 0, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        $ecotone->run('orderService');
        $ecotone->run('orderService');

        self::assertEquals(2, $gateway->getOrderAmount());
    }

    public function test_exception_handling_deleting_multiple_dead_messages(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\Example',
            'Test\Ecotone\Dbal\Fixture\DeadLetter\DeadLetterRightAway',
        ]);

        $gateway = $ecotone->getGateway(OrderGateway::class);

        $gateway->order('coffee');

        $ecotone->run('orderService');
        $ecotone->run('orderService');

        $gateway->order('coffee');

        $ecotone->run('orderService');
        $ecotone->run('orderService');

        $this->assertErrorMessageCount($ecotone, 2, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        $this->deleteAllErrorMessagesById($ecotone);

        $this->assertErrorMessageCount($ecotone, 0, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        $ecotone->run('orderService');
        $ecotone->run('orderService');

        self::assertEquals(0, $gateway->getOrderAmount());
    }

    public function test_same_event_is_stored_in_dead_letter_twice_for_different_endpoints_and_replayed(): void
    {
        $doubleEventHandler = new DoubleEventHandler();
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\DoubleEventHandler',
        ], [$doubleEventHandler]);

        $ecotone->publishEvent(new ExampleEvent('test'));

        $ecotone->run('async');
        $ecotone->run('async');

        $this->assertErrorMessageCount($ecotone, 2);
        self::assertEquals(0, $doubleEventHandler->successfulCalls);

        $this->replayAllErrorMessagesById($ecotone);
        $this->assertErrorMessageCount($ecotone, 0);

        $ecotone->run('async');
        $ecotone->run('async');

        self::assertEquals(2, $doubleEventHandler->successfulCalls);
    }

    public function test_same_event_is_stored_in_dead_letter_twice_for_different_endpoints_and_removed(): void
    {
        $doubleEventHandler = new DoubleEventHandler();
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\DoubleEventHandler',
        ], [$doubleEventHandler]);
        $deadLetter = $ecotone->getGateway(DeadLetterGateway::class);

        $messageId = Uuid::v7()->toRfc4122();
        $ecotone->publishEvent(new ExampleEvent('test'), metadata: [MessageHeaders::MESSAGE_ID => $messageId]);

        $ecotone->run('async');
        $ecotone->run('async');

        $this->assertErrorMessageCount($ecotone, 2);
        $deadLetter->delete($messageId);
        $this->assertErrorMessageCount($ecotone, 1);

        $messages = $deadLetter->list(100, 0);
        self::assertCount(1, $messages);
        /** As new Message Id was generated */
        self::assertNotEquals($messageId, $messages[0]->getMessageId());
        $deadLetter->delete($messages[0]->getMessageId());
        $this->assertErrorMessageCount($ecotone, 0);
    }

    public function test_inbound_channel_adapter_failure_lands_in_dead_letter_and_replays_back_to_handler(): void
    {
        $handler = new class () {
            public const ENDPOINT_ID = 'failingInboundAdapter';
            public const REQUEST_CHANNEL = 'failingInboundAdapterRequestChannel';

            public bool $shouldFail = true;
            public int $invocations = 0;
            /** @var string[] */
            public array $processedPayloads = [];
            private bool $hasEmitted = false;

            #[\Ecotone\Api\Attribute\Scheduled(self::REQUEST_CHANNEL, self::ENDPOINT_ID)]
            #[\Ecotone\Api\Attribute\Poller(executionTimeLimitInMilliseconds: 1, handledMessageLimit: 1)]
            public function emit(): ?string
            {
                if ($this->hasEmitted) {
                    return null;
                }
                $this->hasEmitted = true;

                return 'first-payload';
            }

            #[\Ecotone\Api\Attribute\InternalHandler(self::REQUEST_CHANNEL)]
            public function handle(string $payload): void
            {
                $this->invocations++;
                if ($this->shouldFail) {
                    throw new RuntimeException('simulated');
                }
                $this->processedPayloads[] = $payload;
            }
        };
        $connectionFactory = $this->getConnectionFactory();

        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [
                $handler,
                DbalConnectionFactory::class => $connectionFactory,
                'managerRegistry' => $connectionFactory,
            ],
            extensionObjects: [
                \Ecotone\Api\ExtensionObject\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel(DbalDeadLetterBuilder::STORE_CHANNEL),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ],
            pathToRootCatalog: __DIR__ . '/../../',
            environment: 'prod',
        );

        $ecotone->run('failingInboundAdapter', ExecutionPollingMetadata::createWithTestingSetup(
            handledMessageLimit: 1,
            stopOnError: false,
        ));

        $this->assertErrorMessageCount($ecotone, 1);
        $this->assertSame(1, $handler->invocations, 'Handler must have been invoked once before the failure was captured');
        $this->assertSame([], $handler->processedPayloads);

        $handler->shouldFail = false;
        $this->replayAllErrorMessages($ecotone);

        $this->assertErrorMessageCount($ecotone, 0);
        $this->assertSame(2, $handler->invocations, 'replayAll() must synchronously re-invoke the handler via MessagingEntrypoint — no second run() needed');
        $this->assertSame(['first-payload'], $handler->processedPayloads, 'Replayed Message must carry the original payload back to the handler');

        $ecotone->run('failingInboundAdapter', ExecutionPollingMetadata::createWithTestingSetup(
            handledMessageLimit: 1,
            stopOnError: false,
        ));

        $this->assertSame(2, $handler->invocations, 'Subsequent polls must not re-process the replayed Message (emit() returns null after the first emission)');
        $this->assertSame(['first-payload'], $handler->processedPayloads);
    }

    public function test_dead_letter_message_is_replayed_to_the_chosen_channel_instead_of_the_source_one(): void
    {
        $handler = new class () {
            public const REQUEST_CHANNEL = 'sourceRequestChannel';
            public const TARGET_CHANNEL = 'targetRequestChannel';

            /** @var string[] */
            public array $handledBySource = [];
            /** @var string[] */
            public array $handledByTarget = [];
            private bool $hasEmitted = false;

            #[\Ecotone\Api\Attribute\Scheduled(self::REQUEST_CHANNEL, 'emittingAdapter')]
            #[\Ecotone\Api\Attribute\Poller(executionTimeLimitInMilliseconds: 1, handledMessageLimit: 1)]
            public function emit(): ?string
            {
                if ($this->hasEmitted) {
                    return null;
                }
                $this->hasEmitted = true;

                return 'first-payload';
            }

            #[\Ecotone\Api\Attribute\InternalHandler(self::REQUEST_CHANNEL)]
            public function handleBySource(string $payload): void
            {
                $this->handledBySource[] = $payload;

                throw new RuntimeException('simulated');
            }

            #[\Ecotone\Api\Attribute\InternalHandler(self::TARGET_CHANNEL)]
            public function handleByTarget(string $payload): void
            {
                $this->handledByTarget[] = $payload;
            }
        };
        $connectionFactory = $this->getConnectionFactory();

        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [
                $handler,
                DbalConnectionFactory::class => $connectionFactory,
                'managerRegistry' => $connectionFactory,
            ],
            extensionObjects: [
                \Ecotone\Api\ExtensionObject\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel(DbalDeadLetterBuilder::STORE_CHANNEL),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ],
            pathToRootCatalog: __DIR__ . '/../../',
            environment: 'prod',
        );

        $ecotone->run('emittingAdapter', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));
        $this->assertErrorMessageCount($ecotone, 1);

        $gateway = $ecotone->getGateway(DeadLetterGateway::class);
        $gateway->replay($gateway->list(10, 0)[0]->getMessageId(), $handler::TARGET_CHANNEL);

        $this->assertErrorMessageCount($ecotone, 0);
        $this->assertSame(['first-payload'], $handler->handledBySource);
        $this->assertSame(['first-payload'], $handler->handledByTarget);
    }

    public function test_replaying_to_an_asynchronous_channel_is_refused_and_keeps_the_dead_letter(): void
    {
        $handler = new class () {
            public const QUEUE_CHANNEL = 'refusedTargetQueue';

            /** @var string[] */
            public array $handled = [];
            private bool $hasEmitted = false;

            #[\Ecotone\Api\Attribute\Scheduled('refusedSourceChannel', 'refusedEmittingAdapter')]
            #[\Ecotone\Api\Attribute\Poller(executionTimeLimitInMilliseconds: 1, handledMessageLimit: 1)]
            public function emit(): ?string
            {
                if ($this->hasEmitted) {
                    return null;
                }
                $this->hasEmitted = true;

                return 'payload';
            }

            #[\Ecotone\Api\Attribute\InternalHandler('refusedSourceChannel')]
            public function failing(string $payload): void
            {
                throw new RuntimeException('simulated');
            }

            #[\Ecotone\Api\Attribute\Asynchronous(self::QUEUE_CHANNEL)]
            #[\Ecotone\Api\Attribute\InternalHandler('refusedAsyncHandler', endpointId: 'refusedAsyncHandler.endpoint')]
            public function handleAsynchronously(string $payload): void
            {
                $this->handled[] = $payload;
            }
        };
        $connectionFactory = $this->getConnectionFactory();

        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [
                $handler,
                DbalConnectionFactory::class => $connectionFactory,
                'managerRegistry' => $connectionFactory,
            ],
            extensionObjects: [
                \Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder::createQueueChannel($handler::QUEUE_CHANNEL),
                \Ecotone\Api\ExtensionObject\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel(DbalDeadLetterBuilder::STORE_CHANNEL),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ],
            pathToRootCatalog: __DIR__ . '/../../',
            environment: 'prod',
        );
        $ecotone->run('refusedEmittingAdapter', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));
        $this->assertErrorMessageCount($ecotone, 1);
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);

        try {
            $gateway->replay($gateway->list(10, 0)[0]->getMessageId(), $handler::QUEUE_CHANNEL);
            self::fail('Replaying to an asynchronous channel should be refused');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('refusedTargetQueue', $exception->getMessage());
            $this->assertStringContainsString('asynchronous', $exception->getMessage());
            $this->assertStringContainsString('input channel of the handler', $exception->getMessage());
            $this->assertStringContainsString('without a target channel', $exception->getMessage());
        }

        $this->assertErrorMessageCount($ecotone, 1);
    }

    public function test_dead_letter_message_is_replayed_to_the_input_channel_of_an_asynchronous_handler_and_runs_it_once_its_channel_is_consumed(): void
    {
        $handler = new class () {
            public const QUEUE_CHANNEL = 'inputTargetQueue';

            /** @var string[] */
            public array $handled = [];
            private bool $hasEmitted = false;

            #[\Ecotone\Api\Attribute\Scheduled('inputSourceChannel', 'inputEmittingAdapter')]
            #[\Ecotone\Api\Attribute\Poller(executionTimeLimitInMilliseconds: 1, handledMessageLimit: 1)]
            public function emit(): ?string
            {
                if ($this->hasEmitted) {
                    return null;
                }
                $this->hasEmitted = true;

                return 'payload';
            }

            #[\Ecotone\Api\Attribute\InternalHandler('inputSourceChannel')]
            public function failing(string $payload): void
            {
                throw new RuntimeException('simulated');
            }

            #[\Ecotone\Api\Attribute\Asynchronous(self::QUEUE_CHANNEL)]
            #[\Ecotone\Api\Attribute\InternalHandler('inputAsyncHandler', endpointId: 'inputAsyncHandler.endpoint')]
            public function handleAsynchronously(string $payload): void
            {
                $this->handled[] = $payload;
            }
        };
        $connectionFactory = $this->getConnectionFactory();

        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [
                $handler,
                DbalConnectionFactory::class => $connectionFactory,
                'managerRegistry' => $connectionFactory,
            ],
            extensionObjects: [
                \Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder::createQueueChannel($handler::QUEUE_CHANNEL),
                \Ecotone\Api\ExtensionObject\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel(DbalDeadLetterBuilder::STORE_CHANNEL),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ],
            pathToRootCatalog: __DIR__ . '/../../',
            environment: 'prod',
        );
        $ecotone->run('inputEmittingAdapter', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);

        $gateway->replay($gateway->list(10, 0)[0]->getMessageId(), 'inputAsyncHandler');
        $this->assertSame([], $handler->handled);
        $ecotone->run($handler::QUEUE_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertSame(['payload'], $handler->handled);
        $this->assertErrorMessageCount($ecotone, 0);
    }

    public function test_all_dead_letter_messages_are_replayed_to_the_chosen_channel(): void
    {
        $handler = new class () {
            public const TARGET_CHANNEL = 'allTargetChannel';

            /** @var string[] */
            public array $handledByTarget = [];
            private bool $hasEmitted = false;

            #[\Ecotone\Api\Attribute\Scheduled('allSourceChannel', 'allEmittingAdapter')]
            #[\Ecotone\Api\Attribute\Poller(executionTimeLimitInMilliseconds: 1, handledMessageLimit: 1)]
            public function emit(): ?string
            {
                if ($this->hasEmitted) {
                    return null;
                }
                $this->hasEmitted = true;

                return 'payload';
            }

            #[\Ecotone\Api\Attribute\InternalHandler('allSourceChannel')]
            public function handleBySource(string $payload): void
            {
                throw new RuntimeException('simulated');
            }

            #[\Ecotone\Api\Attribute\InternalHandler(self::TARGET_CHANNEL)]
            public function handleByTarget(string $payload): void
            {
                $this->handledByTarget[] = $payload;
            }
        };
        $connectionFactory = $this->getConnectionFactory();

        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [
                $handler,
                DbalConnectionFactory::class => $connectionFactory,
                'managerRegistry' => $connectionFactory,
            ],
            extensionObjects: [
                \Ecotone\Api\ExtensionObject\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel(DbalDeadLetterBuilder::STORE_CHANNEL),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ],
            pathToRootCatalog: __DIR__ . '/../../',
            environment: 'prod',
        );

        $ecotone->run('allEmittingAdapter', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));

        $ecotone->getGateway(DeadLetterGateway::class)->replayAll($handler::TARGET_CHANNEL);

        $this->assertErrorMessageCount($ecotone, 0);
        $this->assertSame(['payload'], $handler->handledByTarget);
    }

    public function test_replaying_to_unknown_channel_is_refused_and_keeps_the_dead_letter_message(): void
    {
        $handler = new class () {
            public const TARGET_CHANNEL = 'unknownTargetCheckChannel';

            /** @var string[] */
            public array $handledByTarget = [];
            private bool $hasEmitted = false;

            #[\Ecotone\Api\Attribute\Scheduled('unknownSourceChannel', 'unknownEmittingAdapter')]
            #[\Ecotone\Api\Attribute\Poller(executionTimeLimitInMilliseconds: 1, handledMessageLimit: 1)]
            public function emit(): ?string
            {
                if ($this->hasEmitted) {
                    return null;
                }
                $this->hasEmitted = true;

                return 'payload';
            }

            #[\Ecotone\Api\Attribute\InternalHandler('unknownSourceChannel')]
            public function handleBySource(string $payload): void
            {
                throw new RuntimeException('simulated');
            }

            #[\Ecotone\Api\Attribute\InternalHandler(self::TARGET_CHANNEL)]
            public function handleByTarget(string $payload): void
            {
                $this->handledByTarget[] = $payload;
            }
        };
        $connectionFactory = $this->getConnectionFactory();

        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [
                $handler,
                DbalConnectionFactory::class => $connectionFactory,
                'managerRegistry' => $connectionFactory,
            ],
            extensionObjects: [
                \Ecotone\Api\ExtensionObject\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel(DbalDeadLetterBuilder::STORE_CHANNEL),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ],
            pathToRootCatalog: __DIR__ . '/../../',
            environment: 'prod',
        );

        $ecotone->run('unknownEmittingAdapter', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));

        try {
            $ecotone->getGateway(DeadLetterGateway::class)->replayAll('missingChannel');
            $this->fail('Replay to an unknown channel should be refused');
        } catch (\Ecotone\Messaging\Support\InvalidArgumentException $exception) {
            $this->assertStringContainsString('missingChannel', $exception->getMessage());
            $this->assertStringContainsString('omit the target channel', $exception->getMessage());
        }

        $this->assertErrorMessageCount($ecotone, 1);
        $this->assertSame([], $handler->handledByTarget);
    }

    public function test_console_command_replays_dead_letter_message_to_the_chosen_channel(): void
    {
        $handler = new class () {
            public const TARGET_CHANNEL = 'consoleTargetChannel';

            /** @var string[] */
            public array $handledByTarget = [];
            private bool $hasEmitted = false;

            #[\Ecotone\Api\Attribute\Scheduled('consoleSourceChannel', 'consoleEmittingAdapter')]
            #[\Ecotone\Api\Attribute\Poller(executionTimeLimitInMilliseconds: 1, handledMessageLimit: 1)]
            public function emit(): ?string
            {
                if ($this->hasEmitted) {
                    return null;
                }
                $this->hasEmitted = true;

                return 'payload';
            }

            #[\Ecotone\Api\Attribute\InternalHandler('consoleSourceChannel')]
            public function handleBySource(string $payload): void
            {
                throw new RuntimeException('simulated');
            }

            #[\Ecotone\Api\Attribute\InternalHandler(self::TARGET_CHANNEL)]
            public function handleByTarget(string $payload): void
            {
                $this->handledByTarget[] = $payload;
            }
        };
        $connectionFactory = $this->getConnectionFactory();

        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [
                $handler,
                DbalConnectionFactory::class => $connectionFactory,
                'managerRegistry' => $connectionFactory,
            ],
            extensionObjects: [
                \Ecotone\Api\ExtensionObject\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel(DbalDeadLetterBuilder::STORE_CHANNEL),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ],
            pathToRootCatalog: __DIR__ . '/../../',
            environment: 'prod',
        );

        $ecotone->run('consoleEmittingAdapter', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));

        $messageId = $ecotone->getGateway(DeadLetterGateway::class)->list(10, 0)[0]->getMessageId();
        $ecotone->runConsoleCommand('ecotone:deadletter:replay', ['messageId' => $messageId, 'targetChannel' => $handler::TARGET_CHANNEL]);

        $this->assertErrorMessageCount($ecotone, 0);
        $this->assertSame(['payload'], $handler->handledByTarget);
    }

    private function assertErrorMessageCount(FlowTestSupport $ecotone, int $amount, string $deadLetterReference = DeadLetterGateway::class): void
    {
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);

        self::assertCount($amount, $gateway->list(100, 0));
        self::assertEquals($amount, $gateway->count());

        /** @var DeadLetterGateway $gateway */
        $gateway = $ecotone->getGateway($deadLetterReference);

        self::assertCount($amount, $gateway->list(100, 0));
        self::assertEquals($amount, $gateway->count());
    }

    private function deleteAllErrorMessagesById(FlowTestSupport $ecotone): void
    {
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);

        $gateway->delete(array_map(fn (ErrorContext $errorContext) => $errorContext->getMessageId(), $gateway->list(100, 0)));
    }

    public function test_storing_a_dead_letter_reconnects_and_retries_when_the_connection_is_lost(): void
    {
        $connection = $this->getConnection();
        $platform = $connection->getDatabasePlatform();
        if (! $platform instanceof PostgreSQLPlatform && ! $platform instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Ending another session needs a server, which SQLite has no notion of');
        }
        $logger = StubLogger::create();
        $ecotone = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory(), 'logger' => $logger],
            extensionObjects: [DbalConfiguration::createWithDefaults()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
        );
        $deadLetter = $ecotone->getGateway(DeadLetterGateway::class);
        $deadLetter->deleteAll();

        $sessionId = $connection->fetchOne($platform instanceof PostgreSQLPlatform ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()');
        $otherSession = DriverManager::getConnection($connection->getParams());
        $otherSession->executeStatement($platform instanceof PostgreSQLPlatform ? "SELECT pg_terminate_backend({$sessionId})" : "KILL {$sessionId}");
        $otherSession->close();

        $deadLetter->store(StaticMessageBuilder::withPayload('failed')->build());

        self::assertSame(1, $deadLetter->count());
        self::assertSame(['Storing Error Message in dead letter failed. Trying to self-heal and retry.'], $logger->getInfo());
    }

    private function replayAllErrorMessagesById(FlowTestSupport $ecotone): void
    {
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);

        $gateway->replay(array_map(fn (ErrorContext $errorContext) => $errorContext->getMessageId(), $gateway->list(100, 0)));
    }

    private function replayAllErrorMessages(FlowTestSupport $ecotone): void
    {
        $ecotone->getGateway(DeadLetterGateway::class)->replayAll();
    }

    private function bootstrapEcotone(array $namespaces, array $services = [], array $extensionObjects = [], ?OrderService $orderService = null): FlowTestSupport
    {
        $connectionFactory = $this->getConnectionFactory();

        return ($this->bootstrapFlowTesting(
            containerOrAvailableServices: array_merge($services, [
                $orderService ?? new OrderService(),
                DbalConnectionFactory::class => $connectionFactory,
                'managerRegistry' => $connectionFactory,
            ]),
            extensionObjects: array_merge([\Ecotone\Api\ExtensionObject\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)], $extensionObjects),
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ],
            pathToRootCatalog: __DIR__ . '/../../',
            namespaces: $namespaces,
            environment: 'prod',
        ));
    }
}
