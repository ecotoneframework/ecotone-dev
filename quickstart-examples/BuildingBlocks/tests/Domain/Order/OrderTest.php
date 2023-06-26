<?php

declare(strict_types=1);

namespace Tests\App\Domain\Order;

use App\Domain\Order\Command\PlaceOrder;
use App\Domain\Order\Order;
use App\Domain\Product\Command\CreateProduct;
use App\Domain\Product\Product;
use App\Domain\Product\ProductService;
use Ecotone\Lite\EcotoneLite;
use Money\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class OrderTest extends TestCase
{
    public function test_placing_order()
    {
        $orderId = Uuid::uuid4();
        $customerId = Uuid::uuid4()->toString();
        $productTableId = Uuid::uuid4();
        $productChairId = Uuid::uuid4();

        $this->assertEquals(
            Money::EUR(200),
            EcotoneLite::bootstrapFlowTesting([Order::class, Product::class, ProductService::class])
                ->sendCommand(new CreateProduct($productTableId, 'Table', Money::EUR(150)))
                ->sendCommand(new CreateProduct($productChairId, 'Chair', Money::EUR(50)))
                ->sendCommand(new PlaceOrder(
                    $orderId,
                    [$productTableId, $productChairId]
                ), metadata: ['executorId' => $customerId])
                ->getAggregate(Order::class, $orderId)
                ->getTotalPrice()
        );
    }
}