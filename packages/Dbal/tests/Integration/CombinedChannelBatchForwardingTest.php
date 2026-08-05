<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\BatchForwardingConfiguration;
use Ecotone\Messaging\Channel\CombinedMessageChannel;
use Ecotone\Messaging\Channel\PollableChannel\GlobalPollableChannelConfiguration;
use Ecotone\Messaging\Channel\PollableChannel\PollableChannelConfiguration;
use Ecotone\Messaging\Channel\SimpleChannelInterceptorBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\BatchForwarding\AlwaysFailOnPayloadChannelInterceptor;
use Test\Ecotone\Dbal\Fixture\BatchForwarding\FailingPollableChannel;
use Test\Ecotone\Dbal\Fixture\BatchForwarding\FailOnceOnPayloadChannelInterceptor;
use Test\Ecotone\Dbal\Fixture\BatchForwarding\FailOnceOnPayloadPollableChannel;

/**
 * licence Apache-2.0
 * @internal
 */
final class CombinedChannelBatchForwardingTest extends DbalMessagingTestCase
{
    public function test_single_run_of_outbox_consumer_moves_multiple_messages_to_target_asynchronous_channel(): void
    {
        $orderService = new class () {
            /** @var string[] */
            private array $orders = [];

            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
                $this->orders[] = $order;
            }

            #[QueryHandler('order.getRegistered')]
            public function getRegistered(): array
            {
                return $this->orders;
            }
        };

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing']),
                    BatchForwardingConfiguration::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('orderProcessing'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.register', 'latte');
        $messaging->sendCommandWithRoutingKey('order.register', 'cappuccino');

        $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));

        $this->assertCount(3, $this->receiveAllFrom($messaging->getMessageChannel('orderProcessing')));
        $this->assertNull($messaging->getMessageChannel('outbox')->receive());
    }

    public function test_forwarding_as_single_batch_to_target_with_high_throughput_publishing_delivers_all_messages(): void
    {
        $orderService = new class () {
            /** @var string[] */
            private array $orders = [];

            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
                $this->orders[] = $order;
            }

            #[QueryHandler('order.getRegistered')]
            public function getRegistered(): array
            {
                return $this->orders;
            }
        };

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing']),
                    BatchForwardingConfiguration::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('orderProcessing')
                        ->withHighThroughputPublishing(),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.register', 'latte');
        $messaging->sendCommandWithRoutingKey('order.register', 'cappuccino');

        $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));
        $this->assertNull($messaging->getMessageChannel('outbox')->receive());

        $messaging->run('orderProcessing', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 3, maxExecutionTimeInMilliseconds: 10000));

        $this->assertSame(['espresso', 'latte', 'cappuccino'], $messaging->sendQueryWithRouting('order.getRegistered'));
    }

    public function test_single_run_moves_no_more_messages_than_configured_forwarding_batch_size(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing']),
                    BatchForwardingConfiguration::create('outbox')
                        ->withMaxForwardingBatchSize(2),
                    DbalBackedMessageChannelBuilder::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('orderProcessing'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.register', 'latte');
        $messaging->sendCommandWithRoutingKey('order.register', 'cappuccino');

        $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));

        $this->assertCount(2, $this->receiveAllFrom($messaging->getMessageChannel('orderProcessing')));
        $this->assertCount(1, $this->receiveAllFrom($messaging->getMessageChannel('outbox')));
    }

    public function test_batch_forwarding_configuration_requires_enterprise_licence(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        $this->expectException(LicensingException::class);

        EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing']),
                    BatchForwardingConfiguration::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('orderProcessing'),
                ]),
        );
    }

    public function test_combined_channel_without_licence_keeps_one_message_per_run(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing']),
                    DbalBackedMessageChannelBuilder::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('orderProcessing'),
                ]),
        );

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.register', 'latte');
        $messaging->sendCommandWithRoutingKey('order.register', 'cappuccino');

        $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));

        $this->assertCount(1, $this->receiveAllFrom($messaging->getMessageChannel('orderProcessing')));
        $this->assertCount(2, $this->receiveAllFrom($messaging->getMessageChannel('outbox')));
    }

    public function test_messages_for_different_targets_on_shared_outbox_reach_their_own_channels(): void
    {
        $orderService = new class () {
            #[Asynchronous('standardOrders')]
            #[CommandHandler('order.registerStandard', endpointId: 'standardOrderEndpoint')]
            public function registerStandard(string $order): void
            {
            }

            #[Asynchronous('priorityOrders')]
            #[CommandHandler('order.registerPriority', endpointId: 'priorityOrderEndpoint')]
            public function registerPriority(string $order): void
            {
            }
        };

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('standardOrders', ['outbox', 'standardProcessing']),
                    CombinedMessageChannel::create('priorityOrders', ['outbox', 'priorityProcessing']),
                    BatchForwardingConfiguration::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('standardProcessing'),
                    DbalBackedMessageChannelBuilder::create('priorityProcessing'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.registerStandard', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.registerPriority', 'flat white');
        $messaging->sendCommandWithRoutingKey('order.registerStandard', 'latte');
        $messaging->sendCommandWithRoutingKey('order.registerPriority', 'cortado');

        $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));

        $this->assertCount(2, $this->receiveAllFrom($messaging->getMessageChannel('standardProcessing')));
        $this->assertCount(2, $this->receiveAllFrom($messaging->getMessageChannel('priorityProcessing')));
        $this->assertNull($messaging->getMessageChannel('outbox')->receive());
    }

    public function test_plain_asynchronous_dbal_channel_keeps_one_message_per_handled_message(): void
    {
        $orderService = new class () {
            /** @var string[] */
            private array $orders = [];

            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
                $this->orders[] = $order;
            }

            #[QueryHandler('order.getRegistered')]
            public function getRegistered(): array
            {
                return $this->orders;
            }
        };

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalBackedMessageChannelBuilder::create('orders'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.register', 'latte');

        $messaging->run('orders', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));

        $this->assertSame(['espresso'], $messaging->sendQueryWithRouting('order.getRegistered'));
    }

    public function test_multiple_outbox_channels_are_published_by_single_shared_endpoint(): void
    {
        $orderService = new class () {
            #[Asynchronous('standardOrders')]
            #[CommandHandler('order.registerStandard', endpointId: 'standardOrderEndpoint')]
            public function registerStandard(string $order): void
            {
            }

            #[Asynchronous('priorityOrders')]
            #[CommandHandler('order.registerPriority', endpointId: 'priorityOrderEndpoint')]
            public function registerPriority(string $order): void
            {
            }
        };

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('standardOrders', ['standardOutbox', 'standardProcessing']),
                    CombinedMessageChannel::create('priorityOrders', ['priorityOutbox', 'priorityProcessing']),
                    BatchForwardingConfiguration::create('standardOutbox')
                        ->withEndpointId('sharedOutboxPublisher'),
                    BatchForwardingConfiguration::create('priorityOutbox')
                        ->withEndpointId('sharedOutboxPublisher'),
                    DbalBackedMessageChannelBuilder::create('standardOutbox'),
                    DbalBackedMessageChannelBuilder::create('priorityOutbox'),
                    DbalBackedMessageChannelBuilder::create('standardProcessing'),
                    DbalBackedMessageChannelBuilder::create('priorityProcessing'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.registerStandard', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.registerPriority', 'flat white');
        $messaging->sendCommandWithRoutingKey('order.registerStandard', 'latte');

        $messaging->run('sharedOutboxPublisher', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));

        $this->assertCount(2, $this->receiveAllFrom($messaging->getMessageChannel('standardProcessing')));
        $this->assertCount(1, $this->receiveAllFrom($messaging->getMessageChannel('priorityProcessing')));
        $this->assertNull($messaging->getMessageChannel('standardOutbox')->receive());
        $this->assertNull($messaging->getMessageChannel('priorityOutbox')->receive());
    }

    public function test_failed_send_of_single_message_on_target_channel_releases_only_that_message(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['outbox', 'failingProcessing']),
                    BatchForwardingConfiguration::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('outbox'),
                    SimpleMessageChannelBuilder::create('failingProcessing', new FailOnceOnPayloadPollableChannel('cappuccino')),
                    PollableChannelConfiguration::create('failingProcessing', RetryTemplateBuilder::fixedBackOff(1)->maxRetryAttempts(1)->build()),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.register', 'latte');
        $messaging->sendCommandWithRoutingKey('order.register', 'cappuccino');

        $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));

        $this->assertSame(['espresso', 'latte'], $this->payloadsOf($this->receiveAllFrom($messaging->getMessageChannel('failingProcessing'))));

        $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));

        $this->assertSame(['cappuccino'], $this->payloadsOf($this->receiveAllFrom($messaging->getMessageChannel('failingProcessing'))));
        $this->assertNull($messaging->getMessageChannel('outbox')->receive());
    }

    public function test_single_message_is_forwarded_without_waiting_for_source_receive_timeout(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing']),
                    BatchForwardingConfiguration::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('outbox')
                        ->withReceiveTimeout(3000),
                    DbalBackedMessageChannelBuilder::create('orderProcessing'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');

        $startedAt = microtime(true);
        $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 10000));
        $elapsedInMilliseconds = (microtime(true) - $startedAt) * 1000;

        $this->assertCount(1, $this->receiveAllFrom($messaging->getMessageChannel('orderProcessing')));
        $this->assertLessThan(3000, $elapsedInMilliseconds);
    }

    public function test_drained_message_honours_ignore_final_failure_strategy(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [
                DbalConnectionFactory::class => $this->getConnectionFactory(),
                $orderService,
                'alwaysFailingDelivery' => new AlwaysFailOnPayloadChannelInterceptor('cappuccino'),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing']),
                    BatchForwardingConfiguration::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('outbox')
                        ->withFinalFailureStrategy(FinalFailureStrategy::IGNORE),
                    DbalBackedMessageChannelBuilder::create('orderProcessing'),
                    SimpleChannelInterceptorBuilder::create('orderProcessing', 'alwaysFailingDelivery'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.register', 'latte');
        $messaging->sendCommandWithRoutingKey('order.register', 'cappuccino');

        $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));

        $this->assertSame(['espresso', 'latte'], $this->payloadsOf($this->receiveAllFrom($messaging->getMessageChannel('orderProcessing'))));
        $this->assertNull($messaging->getMessageChannel('outbox')->receive());
    }

    public function test_drained_message_with_stop_failure_strategy_stops_consumer_without_message_loss(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [
                DbalConnectionFactory::class => $this->getConnectionFactory(),
                $orderService,
                'alwaysFailingDelivery' => new AlwaysFailOnPayloadChannelInterceptor('cappuccino'),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing']),
                    BatchForwardingConfiguration::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('outbox')
                        ->withFinalFailureStrategy(FinalFailureStrategy::STOP),
                    DbalBackedMessageChannelBuilder::create('orderProcessing'),
                    SimpleChannelInterceptorBuilder::create('orderProcessing', 'alwaysFailingDelivery'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.register', 'latte');
        $messaging->sendCommandWithRoutingKey('order.register', 'cappuccino');

        $consumerStopped = false;
        try {
            $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));
        } catch (RuntimeException) {
            $consumerStopped = true;
        }

        $this->assertTrue($consumerStopped);
        $this->assertCount(3, $this->receiveAllFrom($messaging->getMessageChannel('outbox')));
    }

    public function test_batch_forwarding_configuration_for_non_dbal_channel_fails_at_compile_time(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [$orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['inMemoryOutbox', 'inMemoryProcessing']),
                    BatchForwardingConfiguration::create('inMemoryOutbox'),
                    SimpleMessageChannelBuilder::createQueueChannel('inMemoryOutbox'),
                    SimpleMessageChannelBuilder::createQueueChannel('inMemoryProcessing'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_batch_forwarding_configuration_for_unknown_channel_fails_at_compile_time(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing']),
                    BatchForwardingConfiguration::create('misspelled_outbox'),
                    DbalBackedMessageChannelBuilder::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('orderProcessing'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_forwarded_message_does_not_carry_internal_collector_header(): void
    {
        foreach ([true, false] as $collectorEnabled) {
            $orderService = new class () {
                #[Asynchronous('orders')]
                #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
                public function register(string $order): void
                {
                }
            };

            $messaging = EcotoneLite::bootstrapFlowTesting(
                [$orderService::class],
                [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
                ServiceConfiguration::createWithDefaults()
                    ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                    ->withExtensionObjects([
                        CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing']),
                        BatchForwardingConfiguration::create('outbox'),
                        DbalBackedMessageChannelBuilder::create('outbox'),
                        DbalBackedMessageChannelBuilder::create('orderProcessing'),
                        GlobalPollableChannelConfiguration::createWithDefaults()->withCollector($collectorEnabled),
                    ]),
                licenceKey: LicenceTesting::VALID_LICENCE,
            );

            $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
            $messaging->sendCommandWithRoutingKey('order.register', 'latte');

            $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));

            $forwardedMessages = $this->receiveAllFrom($messaging->getMessageChannel('orderProcessing'));
            $this->assertCount(2, $forwardedMessages);
            foreach ($forwardedMessages as $forwardedMessage) {
                $this->assertFalse($forwardedMessage->getHeaders()->containsKey('collectorBypass'));
            }
        }
    }

    public function test_using_batched_outbox_directly_as_asynchronous_channel_fails_at_compile_time(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }

            #[Asynchronous('outbox')]
            #[CommandHandler('order.registerDirectly', endpointId: 'orderRegisterDirectlyEndpoint')]
            public function registerDirectly(string $order): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing']),
                    BatchForwardingConfiguration::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('orderProcessing'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_using_batched_outbox_directly_as_asynchronous_channel_without_combined_channel_fails_at_compile_time(): void
    {
        $orderService = new class () {
            #[Asynchronous('outbox')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    BatchForwardingConfiguration::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('outbox'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_using_batched_outbox_as_output_channel_fails_at_compile_time(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }

            #[CommandHandler('order.startFlow', outputChannelName: 'outbox')]
            public function startFlow(string $order): string
            {
                return $order;
            }
        };

        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing']),
                    BatchForwardingConfiguration::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('orderProcessing'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_combined_channel_without_batch_forwarding_configuration_keeps_one_message_per_run(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing']),
                    DbalBackedMessageChannelBuilder::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('orderProcessing'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.register', 'latte');
        $messaging->sendCommandWithRoutingKey('order.register', 'cappuccino');

        $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));

        $this->assertCount(1, $this->receiveAllFrom($messaging->getMessageChannel('orderProcessing')));
        $this->assertCount(2, $this->receiveAllFrom($messaging->getMessageChannel('outbox')));
    }

    public function test_failed_forwarding_releases_all_messages_back_to_outbox(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['outbox', 'failingProcessing']),
                    BatchForwardingConfiguration::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('outbox'),
                    SimpleMessageChannelBuilder::create('failingProcessing', new FailingPollableChannel()),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.register', 'latte');
        $messaging->sendCommandWithRoutingKey('order.register', 'cappuccino');

        $messaging->run('outbox', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertCount(3, $this->receiveAllFrom($messaging->getMessageChannel('outbox')));
    }

    public function test_error_channel_is_not_involved_in_failed_forwarding_for_release_and_ignore_strategies(): void
    {
        foreach ([FinalFailureStrategy::RELEASE, FinalFailureStrategy::IGNORE] as $finalFailureStrategy) {
            $orderService = new class () {
                #[Asynchronous('orders')]
                #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
                public function register(string $order): void
                {
                }
            };

            $messaging = EcotoneLite::bootstrapFlowTesting(
                [$orderService::class],
                [
                    DbalConnectionFactory::class => $this->getConnectionFactory(),
                    $orderService,
                    'alwaysFailingDelivery' => new AlwaysFailOnPayloadChannelInterceptor('cappuccino'),
                ],
                ServiceConfiguration::createWithDefaults()
                    ->withDefaultErrorChannel('customErrorChannel')
                    ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                    ->withExtensionObjects([
                        CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing']),
                        BatchForwardingConfiguration::create('outbox'),
                        DbalBackedMessageChannelBuilder::create('outbox')
                            ->withFinalFailureStrategy($finalFailureStrategy),
                        DbalBackedMessageChannelBuilder::create('orderProcessing'),
                        SimpleMessageChannelBuilder::createQueueChannel('customErrorChannel'),
                        SimpleChannelInterceptorBuilder::create('orderProcessing', 'alwaysFailingDelivery'),
                    ]),
                licenceKey: LicenceTesting::VALID_LICENCE,
            );

            $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
            $messaging->sendCommandWithRoutingKey('order.register', 'cappuccino');

            $messaging->run('outbox', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

            $this->assertNull($messaging->getMessageChannel('customErrorChannel')->receive());
            $this->assertSame(['espresso'], $this->payloadsOf($this->receiveAllFrom($messaging->getMessageChannel('orderProcessing'))));
            $expectedRemainingOnOutbox = $finalFailureStrategy === FinalFailureStrategy::RELEASE ? 1 : 0;
            $this->assertCount($expectedRemainingOnOutbox, $this->receiveAllFrom($messaging->getMessageChannel('outbox')));
        }
    }

    public function test_error_channel_is_not_involved_in_failed_forwarding_for_stop_strategy(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [
                DbalConnectionFactory::class => $this->getConnectionFactory(),
                $orderService,
                'alwaysFailingDelivery' => new AlwaysFailOnPayloadChannelInterceptor('cappuccino'),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withDefaultErrorChannel('customErrorChannel')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing']),
                    BatchForwardingConfiguration::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('outbox')
                        ->withFinalFailureStrategy(FinalFailureStrategy::STOP),
                    DbalBackedMessageChannelBuilder::create('orderProcessing'),
                    SimpleMessageChannelBuilder::createQueueChannel('customErrorChannel'),
                    SimpleChannelInterceptorBuilder::create('orderProcessing', 'alwaysFailingDelivery'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.register', 'cappuccino');

        $consumerStopped = false;
        try {
            $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));
        } catch (RuntimeException) {
            $consumerStopped = true;
        }

        $this->assertTrue($consumerStopped);
        $this->assertNull($messaging->getMessageChannel('customErrorChannel')->receive());
        $this->assertCount(2, $this->receiveAllFrom($messaging->getMessageChannel('outbox')));
    }

    public function test_messages_claimed_by_another_process_are_not_published_until_claim_expires(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing']),
                    BatchForwardingConfiguration::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('orderProcessing'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.register', 'latte');

        $this->claimSingleOutboxRowAsAnotherProcess('outbox', claimValidForSeconds: 3600);
        $messaging->run('outbox', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertCount(1, $this->receiveAllFrom($messaging->getMessageChannel('orderProcessing')));

        $this->expireForeignOutboxClaims('outbox');
        $messaging->run('outbox', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertCount(1, $this->receiveAllFrom($messaging->getMessageChannel('orderProcessing')));
        $this->assertNull($messaging->getMessageChannel('outbox')->receive());
    }

    private function claimSingleOutboxRowAsAnotherProcess(string $channelName, int $claimValidForSeconds): void
    {
        $connection = $this->getConnection();
        $rowId = $connection->fetchOne('SELECT id FROM enqueue WHERE queue = ? AND delivery_id IS NULL ORDER BY published_at ASC LIMIT 1', [$channelName]);
        $connection->executeStatement(
            'UPDATE enqueue SET delivery_id = ?, redeliver_after = ? WHERE id = ?',
            ['019890ab-0000-7000-8000-000000000001', time() + $claimValidForSeconds, $rowId],
        );
    }

    private function expireForeignOutboxClaims(string $channelName): void
    {
        $this->getConnection()->executeStatement(
            'UPDATE enqueue SET redeliver_after = ? WHERE queue = ? AND delivery_id IS NOT NULL',
            [time() - 10, $channelName],
        );
    }

    public function test_failed_delivery_of_single_message_releases_only_that_message_without_duplicates(): void
    {
        $messaging = $this->bootstrapWithFailingTargetInterceptor(RuntimeException::class);

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.register', 'latte');
        $messaging->sendCommandWithRoutingKey('order.register', 'cappuccino');

        $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));

        $this->assertSame(['espresso', 'latte'], $this->payloadsOf($this->receiveAllFrom($messaging->getMessageChannel('orderProcessing'))));

        $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));

        $this->assertSame(['cappuccino'], $this->payloadsOf($this->receiveAllFrom($messaging->getMessageChannel('orderProcessing'))));
        $this->assertNull($messaging->getMessageChannel('outbox')->receive());
    }

    public function test_connection_failure_during_delivery_is_recovered_without_duplicates(): void
    {
        $messaging = $this->bootstrapWithFailingTargetInterceptor(ConnectionException::class);

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.register', 'latte');
        $messaging->sendCommandWithRoutingKey('order.register', 'cappuccino');

        try {
            $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));
        } catch (ConnectionException) {
        }
        $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));

        $this->assertSame(['espresso', 'latte', 'cappuccino'], $this->payloadsOf($this->receiveAllFrom($messaging->getMessageChannel('orderProcessing'))));
        $this->assertNull($messaging->getMessageChannel('outbox')->receive());
    }

    private function bootstrapWithFailingTargetInterceptor(string $exceptionClass): FlowTestSupport
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [
                DbalConnectionFactory::class => $this->getConnectionFactory(),
                $orderService,
                'failingDeliveryInterceptor' => new FailOnceOnPayloadChannelInterceptor('cappuccino', $exceptionClass),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing']),
                    BatchForwardingConfiguration::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('outbox'),
                    DbalBackedMessageChannelBuilder::create('orderProcessing'),
                    SimpleChannelInterceptorBuilder::create('orderProcessing', 'failingDeliveryInterceptor'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    /**
     * @param \Ecotone\Messaging\Message[] $messages
     * @return string[]
     */
    private function payloadsOf(array $messages): array
    {
        return array_map(fn ($message) => $message->getPayload(), $messages);
    }

    private function receiveAllFrom(PollableChannel $channel): array
    {
        $messages = [];
        while ($message = $channel->receive()) {
            $messages[] = $message;
        }

        return $messages;
    }
}
