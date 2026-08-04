<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\CombinedMessageChannel;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\BatchForwarding\FailingPollableChannel;

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

    public function test_forwarding_as_single_batch_to_target_with_batched_non_blocking_delivery_delivers_all_messages(): void
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
                        ->withBatchedNonBlockingDelivery(),
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
                    DbalBackedMessageChannelBuilder::create('outbox')
                        ->withMaxForwardingBatchSize(2),
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

    private function receiveAllFrom(PollableChannel $channel): array
    {
        $messages = [];
        while ($message = $channel->receive()) {
            $messages[] = $message;
        }

        return $messages;
    }
}
