<?php

declare(strict_types=1);

namespace Domain\OrderSaga;

use App\Domain\Order\Command\PlaceOrder;
use App\Domain\Order\Order;
use App\Domain\Order\OrderStatus;
use App\Domain\OrderSaga\OrderSaga;
use App\Domain\OrderSaga\ProductReservationService;
use App\Domain\Product\Command\CreateProduct;
use App\Domain\Product\Product;
use App\Domain\Product\ProductService;
use App\Infrastructure\Converter\UuidConverter;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Money\Money;
use PHPUnit\Framework\Attributes\Test;
use Ecotone\Api\Scheduling\Duration;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class OrderSagaTest extends TestCase
{
    public function test_complete_with_order_process_with_success()
    {
        $orderId = Uuid::uuid4();
        $customerId = Uuid::uuid4()->toString();
        $productTableId = Uuid::uuid4();
        $isReservationSuccessful = true;

        $this->assertEquals(
            OrderStatus::COMPLETED,
            $this->getBootstrapFlowTesting($isReservationSuccessful)
                ->sendCommand(new CreateProduct($productTableId, 'Table', Money::EUR(150)))
                ->sendCommand(new PlaceOrder($orderId, [$productTableId]), metadata: ['executorId' => $customerId])
                ->run('orders', ExecutionPollingMetadata::createWithTestingSetup())
                ->sendQueryWithRouting('order.get_status', metadata: ['aggregate.id' => $orderId])
        );
    }

    public function test_order_fails_on_reserving_products()
    {
        $orderId = Uuid::uuid4();
        $customerId = Uuid::uuid4()->toString();
        $productTableId = Uuid::uuid4();
        $isReservationSuccessful = false;
        $ecotoneLite = $this->getBootstrapFlowTesting($isReservationSuccessful);

        $this->assertEquals(
            OrderStatus::PLACED,
            $ecotoneLite
                ->sendCommand(new CreateProduct($productTableId, 'Table', Money::EUR(150)))
                ->sendCommand(new PlaceOrder($orderId, [$productTableId]), metadata: ['executorId' => $customerId])
                ->run('orders', ExecutionPollingMetadata::createWithTestingSetup())
                ->sendQueryWithRouting('order.get_status', metadata: ['aggregate.id' => $orderId])
        );

        $this->assertEquals(
            OrderStatus::CANCELLED,
            $ecotoneLite
                ->advanceTimeBy(Duration::minutes(2))
                ->run('orders', ExecutionPollingMetadata::createWithTestingSetup())
                ->sendQueryWithRouting('order.get_status', metadata: ['aggregate.id' => $orderId])
        );
    }

    public function test_order_reserves_products_on_second_attempt_and_finish_successfully()
    {
        $orderId = Uuid::uuid4();
        $customerId = Uuid::uuid4()->toString();
        $productTableId = Uuid::uuid4();
        $isReservationSuccessful = false;
        $ecotoneLite = $this->getBootstrapFlowTesting($isReservationSuccessful);

        $this->assertEquals(
            OrderStatus::PLACED,
            $ecotoneLite
                ->sendCommand(new CreateProduct($productTableId, 'Table', Money::EUR(150)))
                ->sendCommand(new PlaceOrder($orderId, [$productTableId]), metadata: ['executorId' => $customerId])
                ->run('orders', ExecutionPollingMetadata::createWithTestingSetup())
                ->sendQueryWithRouting('order.get_status', metadata: ['aggregate.id' => $orderId])
        );

        $this->assertEquals(
            OrderStatus::COMPLETED,
            $ecotoneLite
                ->sendCommandWithRouting('allow_product_reservation')
                ->advanceTimeBy(Duration::minutes(2))
                ->run('orders', ExecutionPollingMetadata::createWithTestingSetup())
                ->sendQueryWithRouting('order.get_status', metadata: ['aggregate.id' => $orderId])
        );
    }

    private function getBootstrapFlowTesting(bool $isReservationSuccessful): \Ecotone\Api\Lite\Test\FlowTestSupport
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [OrderSaga::class, Order::class, Product::class, ProductService::class, ProductReservationService::class, UuidConverter::class],
            [new ProductReservationService($isReservationSuccessful), new UuidConverter()],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('orders',true)],
        );
        return $ecotoneLite;
    }
}