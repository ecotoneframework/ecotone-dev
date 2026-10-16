<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Delayed;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DbalChannelClockAfterTransactionTest extends DbalMessagingTestCase
{
    public function test_delayed_asynchronous_handler_runs_once_test_clock_passes_the_delay_after_command_bus_transaction_opened_the_connection(): void
    {
        $channelName = 'orders';
        $handler = new class () {
            public array $confirmed = [];

            #[CommandHandler('order.place')]
            public function place(string $orderId): void
            {
            }

            #[Delayed(seconds: 2)]
            #[Asynchronous('orders')]
            #[CommandHandler('order.confirm', endpointId: 'order.confirm.endpoint')]
            public function confirm(string $orderId): void
            {
                $this->confirmed[] = $orderId;
            }

            #[QueryHandler('order.confirmed')]
            public function confirmed(): array
            {
                return $this->confirmed;
            }
        };

        $ecotoneLite = $this->bootstrapFlowTesting(
            [$handler::class],
            [$handler, DbalConnectionFactory::class => $this->getConnectionFactory(true)],
            [DbalBackedMessageChannelBuilder::create($channelName)->withReceiveTimeout(1)],
            [ModulePackageList::DBAL_PACKAGE],
        );

        $ecotoneLite->sendCommandWithRouting('order.place', 'order-1');
        $ecotoneLite->sendCommandWithRouting('order.confirm', 'order-1');

        $ecotoneLite->run($channelName);
        $this->assertSame([], $ecotoneLite->sendQueryWithRouting('order.confirmed'));

        $ecotoneLite->advanceTimeBy(Duration::seconds(3));

        $ecotoneLite->run($channelName);
        $this->assertSame(['order-1'], $ecotoneLite->sendQueryWithRouting('order.confirmed'));
    }
}
