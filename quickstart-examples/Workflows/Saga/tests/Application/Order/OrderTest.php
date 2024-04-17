<?php

declare(strict_types=1);

namespace Tests\App\Workflow\Saga\Order;

use App\Workflow\Saga\Application\Order\Command\PlaceOrder;
use App\Workflow\Saga\Application\Order\Item;
use App\Workflow\Saga\Application\Order\Order;
use App\Workflow\Saga\Application\Order\OrderService;
use Ecotone\Lite\EcotoneLite;
use Money\Money;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    public function test_creating_an_order(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [
                Order::class, OrderService::class
            ]
        );

        $orderId = '123';

        $this->assertEquals(
            Money::EUR(150),
            $ecotoneLite
                ->sendCommand(new PlaceOrder($orderId, '100', [
                    new Item('Cola', Money::EUR(100)),
                    new Item('Chips', Money::EUR(50))
                ]))
                ->getGateway(OrderService::class)
                ->getTotalPriceFor($orderId)
        );
    }
}