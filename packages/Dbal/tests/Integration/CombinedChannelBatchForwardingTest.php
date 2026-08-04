<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\CombinedMessageChannel;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

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

    private function receiveAllFrom(PollableChannel $channel): array
    {
        $messages = [];
        while ($message = $channel->receive()) {
            $messages[] = $message;
        }

        return $messages;
    }
}
