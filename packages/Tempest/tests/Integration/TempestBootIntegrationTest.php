<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Integration;

use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Ecotone\Tempest\EcotoneTempestConfiguration;
use PHPUnit\Framework\TestCase;
use Tempest\Core\Tempest;
use Tempest\Discovery\DiscoveryLocation;
use Test\Ecotone\Tempest\Fixture\Order\GetOrder;
use Test\Ecotone\Tempest\Fixture\Order\Order;
use Test\Ecotone\Tempest\Fixture\Order\OrderService;
use Test\Ecotone\Tempest\Fixture\Order\PlaceOrder;
use Test\Ecotone\Tempest\Fixture\Product\GetProduct;
use Test\Ecotone\Tempest\Fixture\Product\Product;
use Test\Ecotone\Tempest\Fixture\Product\ProductService;
use Test\Ecotone\Tempest\Fixture\Product\RegisterProduct;

/**
 * @internal
 * licence Apache-2.0
 */
final class TempestBootIntegrationTest extends TestCase
{
    private \Tempest\Container\Container $container;

    protected function setUp(): void
    {
        // Create discovery locations that include our test fixtures and Ecotone integration
        $discoveryLocations = [
            EcotoneTempestConfiguration::getDiscoveryPaths(),
            new DiscoveryLocation('Test\\Ecotone\\Tempest\\Fixture\\', __DIR__ . '/../Fixture/'),
        ];

        // Boot Tempest directly to get the container
        $this->container = Tempest::boot(__DIR__ . '/../../', $discoveryLocations);

        // Manually register the services if they're not discovered automatically
        if (!$this->container->has(OrderService::class)) {
            $this->container->singleton(OrderService::class, new OrderService());
        }

        if (!$this->container->has(ProductService::class)) {
            $this->container->singleton(ProductService::class, new ProductService());
        }
    }

    public function test_ecotone_messaging_system_is_available(): void
    {
        $this->assertInstanceOf(
            \Ecotone\Messaging\Config\ConfiguredMessagingSystem::class,
            $this->container->get(\Ecotone\Messaging\Config\ConfiguredMessagingSystem::class)
        );
    }

    public function test_ecotone_command_bus_is_available(): void
    {
        $this->assertInstanceOf(
            CommandBus::class,
            $this->container->get(CommandBus::class)
        );
    }

    public function test_ecotone_query_bus_is_available(): void
    {
        $this->assertInstanceOf(
            QueryBus::class,
            $this->container->get(QueryBus::class)
        );
    }

    public function test_ecotone_command_bus_works(): void
    {
        /** @var CommandBus $commandBus */
        $commandBus = $this->container->get(CommandBus::class);

        // Send command through Ecotone's CommandBus
        $commandBus->send(new PlaceOrder('user123', ['product1', 'product2']));

        // Verify command was handled
        $orders = OrderService::getOrders();
        $this->assertCount(1, $orders);

        $order = array_values($orders)[0];
        $this->assertInstanceOf(Order::class, $order);
        $this->assertEquals('user123', $order->userId);
        $this->assertEquals(['product1', 'product2'], $order->productIds);
    }

    public function test_ecotone_query_bus_works(): void
    {
        /** @var CommandBus $commandBus */
        $commandBus = $this->container->get(CommandBus::class);
        /** @var QueryBus $queryBus */
        $queryBus = $this->container->get(QueryBus::class);

        // First register a product
        $commandBus->send(new RegisterProduct('prod123', 'Test Product', 99.99));

        // Query for the product using Ecotone's QueryBus
        $product = $queryBus->send(new GetProduct('prod123'));

        $this->assertInstanceOf(Product::class, $product);
        $this->assertEquals('prod123', $product->productId);
        $this->assertEquals('Test Product', $product->name);
        $this->assertEquals(99.99, $product->price);
    }

    public function test_command_and_query_integration(): void
    {
        /** @var CommandBus $commandBus */
        $commandBus = $this->container->get(CommandBus::class);
        /** @var QueryBus $queryBus */
        $queryBus = $this->container->get(QueryBus::class);

        // Place an order
        $commandBus->send(new PlaceOrder('user456', ['product3']));

        // Get the order ID from the stored orders
        $orders = OrderService::getOrders();
        $this->assertCount(1, $orders);

        $orderId = array_keys($orders)[0];

        // Query for the order
        $order = $queryBus->send(new GetOrder($orderId));

        $this->assertInstanceOf(Order::class, $order);
        $this->assertEquals('user456', $order->userId);
        $this->assertEquals(['product3'], $order->productIds);
    }


}
