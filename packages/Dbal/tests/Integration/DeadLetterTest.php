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
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Test\StaticMessageBuilder;
use Ecotone\Test\StubLogger;
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

    public function test_a_dead_letter_replayed_to_the_asynchronous_channel_of_its_handler_runs_the_handler(): void
    {
        [$ecotone, $handler] = $this->failedAsynchronousOrder();

        $this->deadLetters($ecotone)->replay($this->firstDeadLetterId($ecotone), $handler::QUEUE);
        $handler->fail = false;
        $ecotone->run($handler::QUEUE, ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        $this->assertSame(['order-1'], $handler->placed);
        $this->assertErrorMessageCount($ecotone, 0);
    }

    public function test_a_dead_letter_replayed_to_another_asynchronous_channel_runs_the_original_handler_once_that_channel_is_consumed(): void
    {
        [$ecotone, $handler] = $this->failedAsynchronousOrder();
        $handler->fail = false;

        $this->deadLetters($ecotone)->replay($this->firstDeadLetterId($ecotone), $handler::OTHER_QUEUE);
        $ecotone->run($handler::QUEUE, ExecutionPollingMetadata::createWithTestingSetup());
        $this->assertSame([], $handler->placed);
        $ecotone->run($handler::OTHER_QUEUE, ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertSame(['order-1'], $handler->placed);
        $this->assertErrorMessageCount($ecotone, 0);
    }

    public function test_all_dead_letters_are_replayed_to_another_asynchronous_channel_and_run_the_original_handler(): void
    {
        [$ecotone, $handler] = $this->failedAsynchronousOrder();
        $handler->fail = false;

        $this->deadLetters($ecotone)->replayAll($handler::OTHER_QUEUE);
        $ecotone->run($handler::OTHER_QUEUE, ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertSame(['order-1'], $handler->placed);
        $this->assertErrorMessageCount($ecotone, 0);
    }

    public function test_the_console_replays_a_dead_letter_to_another_asynchronous_channel_and_the_original_handler_runs(): void
    {
        [$ecotone, $handler] = $this->failedAsynchronousOrder();
        $handler->fail = false;

        $ecotone->runConsoleCommand('ecotone:deadletter:replay', ['messageId' => $this->firstDeadLetterId($ecotone), 'targetChannel' => $handler::OTHER_QUEUE]);
        $ecotone->run($handler::OTHER_QUEUE, ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertSame(['order-1'], $handler->placed);
        $this->assertErrorMessageCount($ecotone, 0);
    }

    public function test_a_dead_letter_replayed_to_a_queue_no_handler_is_bound_to_keeps_the_routing_slip_of_its_handler(): void
    {
        $interceptor = new class () {
            /** @var string[] */
            public array $routingSlips = [];

            #[\Ecotone\Api\Attribute\ChannelInterceptor('idleQueue')]
            public function record(#[\Ecotone\Api\Attribute\Payload] string $payload, #[\Ecotone\Api\Attribute\Header(MessageHeaders::ROUTING_SLIP)] string $routingSlip): string
            {
                $this->routingSlips[] = $routingSlip;

                return $payload;
            }
        };
        [$ecotone, $handler] = $this->failedAsynchronousOrder([$interceptor]);

        $this->deadLetters($ecotone)->replay($this->firstDeadLetterId($ecotone), 'idleQueue');

        $this->assertErrorMessageCount($ecotone, 0);
        $this->assertCount(1, $interceptor->routingSlips);
        $this->assertStringContainsString('order.place', $interceptor->routingSlips[0]);
    }

    public function test_replaying_to_a_channel_that_is_not_asynchronous_is_refused_on_every_path_and_keeps_the_dead_letter(): void
    {
        [$ecotone, $handler] = $this->failedAsynchronousOrder();
        $messageId = $this->firstDeadLetterId($ecotone);
        $refusals = [
            fn () => $this->deadLetters($ecotone)->replay($messageId, $handler::SYNCHRONOUS_CHANNEL),
            fn () => $this->deadLetters($ecotone)->replayAll($handler::SYNCHRONOUS_CHANNEL),
            fn () => $ecotone->runConsoleCommand('ecotone:deadletter:replay', ['messageId' => $messageId, 'targetChannel' => $handler::SYNCHRONOUS_CHANNEL]),
            fn () => $ecotone->runConsoleCommand('ecotone:deadletter:replayAll', ['targetChannel' => $handler::SYNCHRONOUS_CHANNEL]),
        ];

        foreach ($refusals as $replay) {
            try {
                $replay();
                self::fail('Replaying to a channel that is not asynchronous should be refused');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($handler::SYNCHRONOUS_CHANNEL, $exception->getMessage());
                $this->assertStringContainsString('not an asynchronous', $exception->getMessage());
                $this->assertStringContainsString('asynchronous channels of the service', $exception->getMessage());
                $this->assertStringContainsString('omit the target channel', $exception->getMessage());
            }
        }

        $this->assertSame([], $handler->placed);
        $this->assertErrorMessageCount($ecotone, 1);
    }

    public function test_a_dead_letter_whose_time_to_live_has_expired_is_handled_after_a_targeted_replay(): void
    {
        [$ecotone, $handler] = $this->failedAsynchronousOrder(metadata: [MessageHeaders::TIME_TO_LIVE => 5000], dbalChannels: true);
        $handler->fail = false;
        $ecotone->advanceTimeBy(\Ecotone\Api\Scheduling\Duration::seconds(60));

        $this->deadLetters($ecotone)->replay($this->firstDeadLetterId($ecotone), $handler::OTHER_QUEUE);
        $ecotone->run($handler::OTHER_QUEUE, ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertSame(['order-1'], $handler->placed);
    }

    public function test_a_dead_letter_whose_delivery_was_delayed_is_not_delayed_again_after_a_targeted_replay(): void
    {
        [$ecotone, $handler] = $this->failedAsynchronousOrder(metadata: [MessageHeaders::DELIVERY_DELAY => 3000], dbalChannels: true, advanceSecondsBeforeFailing: 3);
        $handler->fail = false;

        $this->deadLetters($ecotone)->replay($this->firstDeadLetterId($ecotone), $handler::OTHER_QUEUE);
        $ecotone->run($handler::OTHER_QUEUE, ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertSame(['order-1'], $handler->placed);
    }

    public function test_a_dead_letter_that_fails_again_after_a_targeted_replay_is_replayed_back_to_the_channel_it_was_replayed_to(): void
    {
        [$ecotone, $handler] = $this->failedAsynchronousOrder();

        $this->deadLetters($ecotone)->replay($this->firstDeadLetterId($ecotone), $handler::OTHER_QUEUE);
        $ecotone->run($handler::OTHER_QUEUE, ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));
        $this->assertErrorMessageCount($ecotone, 1);
        $handler->fail = false;
        $this->deadLetters($ecotone)->replay($this->firstDeadLetterId($ecotone));
        $ecotone->run($handler::OTHER_QUEUE, ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertSame(['order-1'], $handler->placed);
    }

    private function deadLetters(FlowTestSupport $ecotone): DeadLetterGateway
    {
        return $ecotone->getGateway(DeadLetterGateway::class);
    }

    private function firstDeadLetterId(FlowTestSupport $ecotone): string
    {
        return $this->deadLetters($ecotone)->list(10, 0)[0]->getMessageId();
    }

    /**
     * @param object[] $additionalServices
     * @return array{FlowTestSupport, object}
     */
    private function failedAsynchronousOrder(array $additionalServices = [], array $metadata = [], bool $dbalChannels = false, ?int $advanceSecondsBeforeFailing = null): array
    {
        $handler = new class () {
            public const QUEUE = 'ordersQueue';
            public const OTHER_QUEUE = 'otherQueue';
            public const SYNCHRONOUS_CHANNEL = 'synchronousChannel';

            public bool $fail = true;
            /** @var string[] */
            public array $placed = [];

            #[\Ecotone\Api\Attribute\Asynchronous(self::QUEUE)]
            #[\Ecotone\Api\Attribute\CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderId): void
            {
                if ($this->fail) {
                    throw new RuntimeException('simulated');
                }

                $this->placed[] = $orderId;
            }

            #[\Ecotone\Api\Attribute\InternalHandler(self::SYNCHRONOUS_CHANNEL)]
            public function synchronous(string $orderId): void
            {
                $this->placed[] = $orderId;
            }
        };
        $connectionFactory = $this->getConnectionFactory();
        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [$handler::class, ...array_map(fn (object $service) => $service::class, $additionalServices)],
            containerOrAvailableServices: [
                $handler,
                ...$additionalServices,
                DbalConnectionFactory::class => $connectionFactory,
                'managerRegistry' => $connectionFactory,
            ],
            extensionObjects: [
                ...array_map(
                    fn (string $channel) => $dbalChannels ? \Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder::create($channel) : \Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder::createQueueChannel($channel),
                    [$handler::QUEUE, $handler::OTHER_QUEUE, 'idleQueue']
                ),
                \Ecotone\Api\ExtensionObject\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel(DbalDeadLetterBuilder::STORE_CHANNEL),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ],
            pathToRootCatalog: __DIR__ . '/../../',
            environment: 'prod',
            licenceKey: $additionalServices === [] ? null : \Ecotone\Test\LicenceTesting::VALID_LICENCE,
        );
        $ecotone->sendCommandWithRouting('order.place', 'order-1', metadata: $metadata);
        if ($advanceSecondsBeforeFailing !== null) {
            $ecotone->run($handler::QUEUE, ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));
            $this->assertErrorMessageCount($ecotone, 0);
            $ecotone->advanceTimeBy(\Ecotone\Api\Scheduling\Duration::seconds($advanceSecondsBeforeFailing));
        }
        $ecotone->run($handler::QUEUE, ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));
        $this->assertErrorMessageCount($ecotone, 1);

        return [$ecotone, $handler];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('classRoutedHandlers')]
    public function test_a_dead_letter_cannot_be_replayed_to_the_class_of_a_class_routed_command(object $payload): void
    {
        $handler = $this->replayProbeHandler($payload);
        $ecotone = $this->bootstrapReplayProbe($handler);
        $ecotone->run('replayProbeEmitter', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);

        try {
            $gateway->replay($gateway->list(10, 0)[0]->getMessageId(), $payload::class);
            self::fail('A class-routed command has no channel of that name to replay to');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('no channel or handler with that name exists', $exception->getMessage());
        }

        $this->assertSame([], $handler->ran);
        $this->assertErrorMessageCount($ecotone, 1);
    }

    /**
     * @return iterable<string, array{object}>
     */
    public static function classRoutedHandlers(): iterable
    {
        yield 'class routed command handler' => [new ReplayProbeClassRouted()];
        yield 'asynchronous class routed command handler' => [new ReplayProbeClassRoutedAsync()];
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
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('missingChannel', $exception->getMessage());
            $this->assertStringContainsString('omit the target channel', $exception->getMessage());
        }

        $this->assertErrorMessageCount($ecotone, 1);
        $this->assertSame([], $handler->handledByTarget);
    }

    private function replayProbeHandler(object $payload): object
    {
        $handler = new class () {
            public const QUEUE_CHANNEL = 'replayProbeQueue';

            /** @var string[] */
            public array $ran = [];
            public ?object $payload = null;
            private bool $hasEmitted = false;

            #[\Ecotone\Api\Attribute\Scheduled('replayProbeSource', 'replayProbeEmitter')]
            #[\Ecotone\Api\Attribute\Poller(executionTimeLimitInMilliseconds: 1, handledMessageLimit: 1)]
            public function emit(): ?\Ecotone\Api\Messaging\Message
            {
                if ($this->hasEmitted) {
                    return null;
                }
                $this->hasEmitted = true;

                return StaticMessageBuilder::withPayload($this->payload)->setContentType(\Ecotone\Api\ExtensionObject\MediaType::createApplicationXPHP())->build();
            }

            #[\Ecotone\Api\Attribute\InternalHandler('replayProbeSource')]
            public function failing(object $payload): void
            {
                throw new RuntimeException('simulated');
            }

            #[\Ecotone\Api\Attribute\CommandHandler]
            public function classRouted(ReplayProbeClassRouted $command): void
            {
                $this->ran[] = 'class_routed';
            }

            #[\Ecotone\Api\Attribute\Asynchronous(self::QUEUE_CHANNEL)]
            #[\Ecotone\Api\Attribute\CommandHandler(endpointId: 'replayProbe.classRoutedAsync')]
            public function classRoutedAsynchronously(ReplayProbeClassRoutedAsync $command): void
            {
                $this->ran[] = 'class_routed_async';
            }

            #[\Ecotone\Api\Attribute\CommandHandler('replay.probe.keyed')]
            public function keyed(ReplayProbeKeyed $command): void
            {
                $this->ran[] = 'keyed';
            }

            #[\Ecotone\Api\Attribute\Asynchronous(self::QUEUE_CHANNEL)]
            #[\Ecotone\Api\Attribute\CommandHandler('replay.probe.keyed_async', endpointId: 'replayProbe.keyedAsync')]
            public function keyedAsynchronously(ReplayProbeKeyed $command): void
            {
                $this->ran[] = 'keyed_async';
            }

            #[\Ecotone\Api\Attribute\InternalHandler('replay.probe.internal')]
            public function internal(ReplayProbeKeyed $command): void
            {
                $this->ran[] = 'internal';
            }
        };
        $handler->payload = $payload;
        ReplayProbeAggregate::$created = [];

        return $handler;
    }

    private function bootstrapReplayProbe(object $handler): FlowTestSupport
    {
        $connectionFactory = $this->getConnectionFactory();
        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [$handler::class, ReplayProbeConverter::class, ReplayProbeAggregate::class],
            containerOrAvailableServices: [
                $handler,
                new ReplayProbeConverter(),
                DbalConnectionFactory::class => $connectionFactory,
                'managerRegistry' => $connectionFactory,
            ],
            extensionObjects: [
                \Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder::createQueueChannel($handler::QUEUE_CHANNEL),
                \Ecotone\Api\ExtensionObject\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel(DbalDeadLetterBuilder::STORE_CHANNEL)->withDefaultSerializationMediaType(\Ecotone\Api\ExtensionObject\MediaType::createApplicationJson()),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ],
            pathToRootCatalog: __DIR__ . '/../../',
            environment: 'prod',
        );

        return $ecotone;
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

final class ReplayProbeClassRouted
{
}

final class ReplayProbeClassRoutedAsync
{
}

final class ReplayProbeKeyed
{
}

#[\Ecotone\Api\Attribute\Aggregate]
final class ReplayProbeAggregate
{
    /** @var string[] */
    public static array $created = [];

    private function __construct(#[\Ecotone\Api\Attribute\Identifier] private string $id)
    {
    }

    #[\Ecotone\Api\Attribute\CommandHandler('replay.probe.aggregate')]
    public static function create(ReplayProbeKeyed $command): self
    {
        self::$created[] = 'created';

        return new self('replay-probe');
    }
}

#[\Ecotone\Api\Attribute\MediaTypeConverter]
final class ReplayProbeConverter implements \Ecotone\Messaging\Conversion\Converter
{
    private const CLASSES = [ReplayProbeClassRouted::class, ReplayProbeClassRoutedAsync::class, ReplayProbeKeyed::class];

    public function convert($source, \Ecotone\Messaging\Handler\Type $sourceType, \Ecotone\Api\ExtensionObject\MediaType $sourceMediaType, \Ecotone\Messaging\Handler\Type $targetType, \Ecotone\Api\ExtensionObject\MediaType $targetMediaType)
    {
        if ($targetMediaType->isCompatibleWith(\Ecotone\Api\ExtensionObject\MediaType::createApplicationJson())) {
            return '{}';
        }

        $class = $targetType->getTypeHint();

        return new $class();
    }

    public function matches(\Ecotone\Messaging\Handler\Type $sourceType, \Ecotone\Api\ExtensionObject\MediaType $sourceMediaType, \Ecotone\Messaging\Handler\Type $targetType, \Ecotone\Api\ExtensionObject\MediaType $targetMediaType): bool
    {
        return (in_array($sourceType->getTypeHint(), self::CLASSES, true) && $targetMediaType->isCompatibleWith(\Ecotone\Api\ExtensionObject\MediaType::createApplicationJson()))
            || ($sourceMediaType->isCompatibleWith(\Ecotone\Api\ExtensionObject\MediaType::createApplicationJson()) && in_array($targetType->getTypeHint(), self::CLASSES, true));
    }
}
