<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Integration;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Ecotone\Tempest\Container\EcotoneInitializer;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Tempest\Core\AppConfig;
use Tempest\Core\Environment;
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
final class EcotoneIntegrationTest extends TestCase
{
    private GenericContainer $container;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a simple container
        $this->container = new GenericContainer();
        GenericContainer::setInstance($this->container);
        
        // Register AppConfig
        $appConfig = new AppConfig(environment: Environment::TESTING);
        $this->container->singleton(AppConfig::class, $appConfig);
        
        // Register the service classes in the container first
        $orderService = new OrderService();
        $productService = new ProductService();
        $this->container->singleton(OrderService::class, $orderService);
        $this->container->singleton(ProductService::class, $productService);

        // Initialize Ecotone manually
        $ecotoneInitializer = new EcotoneInitializer();
        $messagingSystem = $ecotoneInitializer->initialize($this->container);
        
        // Register messaging system
        $this->container->singleton(ConfiguredMessagingSystem::class, $messagingSystem);
        
        // Register buses
        $this->container->singleton(CommandBus::class, fn() => $messagingSystem->getGatewayByName(CommandBus::class));
        $this->container->singleton(QueryBus::class, fn() => $messagingSystem->getGatewayByName(QueryBus::class));
        
        // Reset test data
        OrderService::reset();
        ProductService::reset();
    }

    public function test_ecotone_messaging_system_is_available(): void
    {
        $this->assertInstanceOf(
            ConfiguredMessagingSystem::class,
            $this->container->get(ConfiguredMessagingSystem::class)
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

    public function test_edge_case_empty_product_list(): void
    {
        /** @var CommandBus $commandBus */
        $commandBus = $this->container->get(CommandBus::class);
        
        // Test edge case with empty product list
        $commandBus->send(new PlaceOrder('user789', []));
        
        $orders = OrderService::getOrders();
        $this->assertCount(1, $orders);
        
        $order = array_values($orders)[0];
        $this->assertEquals('user789', $order->userId);
        $this->assertEquals([], $order->productIds);
    }

    public function test_edge_case_price_boundaries(): void
    {
        /** @var CommandBus $commandBus */
        $commandBus = $this->container->get(CommandBus::class);
        /** @var QueryBus $queryBus */
        $queryBus = $this->container->get(QueryBus::class);
        
        // Test edge cases for price - 0.00 and 0.01
        $commandBus->send(new RegisterProduct('free-product', 'Free Product', 0.00));
        $commandBus->send(new RegisterProduct('cheap-product', 'Cheap Product', 0.01));
        
        $freeProduct = $queryBus->send(new GetProduct('free-product'));
        $cheapProduct = $queryBus->send(new GetProduct('cheap-product'));
        
        $this->assertEquals(0.00, $freeProduct->price);
        $this->assertEquals(0.01, $cheapProduct->price);
    }
}
