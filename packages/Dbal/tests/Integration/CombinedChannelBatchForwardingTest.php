<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\CombinedMessageChannel;
use Ecotone\Messaging\Channel\PollableChannel\PollableChannelConfiguration;
use Ecotone\Messaging\Channel\SimpleChannelInterceptorBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\PollableChannel;
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
                    CombinedMessageChannel::create('orders', ['outbox', 'orderProcessing'])
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

    public function test_single_run_without_enterprise_licence_moves_one_message_only(): void
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

    public function test_non_auto_acked_source_is_not_drained(): void
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
                    SimpleMessageChannelBuilder::createQueueChannel('outbox', isAutoAcked: false),
                    DbalBackedMessageChannelBuilder::create('orderProcessing'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.register', 'latte');
        $messaging->sendCommandWithRoutingKey('order.register', 'cappuccino');

        $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));

        $this->assertCount(1, $this->receiveAllFrom($messaging->getMessageChannel('orderProcessing')));
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

    public function test_failed_forwarding_keeps_all_messages_available_on_outbox(): void
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
                    DbalBackedMessageChannelBuilder::create('outbox'),
                    SimpleMessageChannelBuilder::create('failingProcessing', new FailingPollableChannel()),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.register', 'latte');
        $messaging->sendCommandWithRoutingKey('order.register', 'cappuccino');

        $forwardingFailed = false;
        try {
            $messaging->run('outbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 5000));
        } catch (RuntimeException) {
            $forwardingFailed = true;
        }

        $this->assertTrue($forwardingFailed);
        $this->assertCount(3, $this->receiveAllFrom($messaging->getMessageChannel('outbox')));
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
