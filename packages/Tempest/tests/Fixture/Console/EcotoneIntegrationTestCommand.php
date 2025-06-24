<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\Console;

use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Tempest\Console\HasConsole;
use Test\Ecotone\Tempest\Fixture\Order\GetOrder;
use Test\Ecotone\Tempest\Fixture\Order\Order;
use Test\Ecotone\Tempest\Fixture\Order\OrderService;
use Test\Ecotone\Tempest\Fixture\Order\PlaceOrder;
use Test\Ecotone\Tempest\Fixture\Product\GetProduct;
use Test\Ecotone\Tempest\Fixture\Product\Product;
use Test\Ecotone\Tempest\Fixture\Product\ProductService;
use Test\Ecotone\Tempest\Fixture\Product\RegisterProduct;

/**
 * licence Apache-2.0
 */
final readonly class EcotoneIntegrationTestCommand
{
    use HasConsole;

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {
    }

    #[ConsoleCommand(name: 'ecotone:integration-test', description: 'Tests Ecotone integration with Tempest through full application bootstrap')]
    public function __invoke(): ExitCode
    {
        // Reset test data
        OrderService::reset();
        ProductService::reset();

        $this->console->writeln('<info>Starting Ecotone-Tempest integration tests...</info>');

        try {
            $this->testCommandBusWorks();
            $this->testQueryBusWorks();
            $this->testCommandAndQueryIntegration();
            $this->testEdgeCaseEmptyProductList();
            $this->testEdgeCasePriceBoundaries();

            $this->console->writeln('<success>All integration tests passed!</success>');
            return ExitCode::SUCCESS;
        } catch (\Throwable $exception) {
            $this->console->writeln('<error>Integration test failed: ' . $exception->getMessage() . '</error>');
            return ExitCode::ERROR;
        }
    }

    private function testCommandBusWorks(): void
    {
        $this->console->writeln('Testing CommandBus functionality...');

        // Send command through Ecotone's CommandBus
        $this->commandBus->send(new PlaceOrder('user123', ['product1', 'product2']));

        // Verify command was handled
        $orders = OrderService::getOrders();
        if (count($orders) !== 1) {
            throw new \RuntimeException('Expected 1 order, got ' . count($orders));
        }

        $order = array_values($orders)[0];
        if (!$order instanceof Order) {
            throw new \RuntimeException('Expected Order instance, got ' . get_class($order));
        }

        if ($order->userId !== 'user123') {
            throw new \RuntimeException('Expected userId user123, got ' . $order->userId);
        }

        if ($order->productIds !== ['product1', 'product2']) {
            throw new \RuntimeException('Expected productIds [product1, product2], got ' . json_encode($order->productIds));
        }

        $this->console->writeln('✓ CommandBus test passed');
    }

    private function testQueryBusWorks(): void
    {
        $this->console->writeln('Testing QueryBus functionality...');

        // First register a product
        $this->commandBus->send(new RegisterProduct('prod123', 'Test Product', 99.99));

        // Query for the product using Ecotone's QueryBus
        $product = $this->queryBus->send(new GetProduct('prod123'));

        if (!$product instanceof Product) {
            throw new \RuntimeException('Expected Product instance, got ' . get_class($product));
        }

        if ($product->productId !== 'prod123') {
            throw new \RuntimeException('Expected productId prod123, got ' . $product->productId);
        }

        if ($product->name !== 'Test Product') {
            throw new \RuntimeException('Expected name Test Product, got ' . $product->name);
        }

        if ($product->price !== 99.99) {
            throw new \RuntimeException('Expected price 99.99, got ' . $product->price);
        }

        $this->console->writeln('✓ QueryBus test passed');
    }

    private function testCommandAndQueryIntegration(): void
    {
        $this->console->writeln('Testing Command and Query integration...');

        // Place an order
        $this->commandBus->send(new PlaceOrder('user456', ['product3']));

        // Get the order ID from the stored orders
        $orders = OrderService::getOrders();
        if (count($orders) !== 2) { // We already have one from previous test
            throw new \RuntimeException('Expected 2 orders, got ' . count($orders));
        }

        // Find the new order
        $newOrder = null;
        foreach ($orders as $orderId => $order) {
            if ($order->userId === 'user456') {
                $newOrder = $order;
                $newOrderId = $orderId;
                break;
            }
        }

        if ($newOrder === null) {
            throw new \RuntimeException('Could not find order for user456');
        }

        // Query for the order
        $queriedOrder = $this->queryBus->send(new GetOrder($newOrderId));

        if (!$queriedOrder instanceof Order) {
            throw new \RuntimeException('Expected Order instance, got ' . get_class($queriedOrder));
        }

        if ($queriedOrder->userId !== 'user456') {
            throw new \RuntimeException('Expected userId user456, got ' . $queriedOrder->userId);
        }

        if ($queriedOrder->productIds !== ['product3']) {
            throw new \RuntimeException('Expected productIds [product3], got ' . json_encode($queriedOrder->productIds));
        }

        $this->console->writeln('✓ Command and Query integration test passed');
    }

    private function testEdgeCaseEmptyProductList(): void
    {
        $this->console->writeln('Testing edge case: empty product list...');

        // Test edge case with empty product list
        $this->commandBus->send(new PlaceOrder('user789', []));

        $orders = OrderService::getOrders();
        if (count($orders) !== 3) { // We already have two from previous tests
            throw new \RuntimeException('Expected 3 orders, got ' . count($orders));
        }

        // Find the new order
        $emptyOrder = null;
        foreach ($orders as $order) {
            if ($order->userId === 'user789') {
                $emptyOrder = $order;
                break;
            }
        }

        if ($emptyOrder === null) {
            throw new \RuntimeException('Could not find order for user789');
        }

        if ($emptyOrder->userId !== 'user789') {
            throw new \RuntimeException('Expected userId user789, got ' . $emptyOrder->userId);
        }

        if ($emptyOrder->productIds !== []) {
            throw new \RuntimeException('Expected empty productIds, got ' . json_encode($emptyOrder->productIds));
        }

        $this->console->writeln('✓ Edge case empty product list test passed');
    }

    private function testEdgeCasePriceBoundaries(): void
    {
        $this->console->writeln('Testing edge case: price boundaries (0.00 vs 0.01)...');

        // Test edge cases for price - 0.00 and 0.01
        $this->commandBus->send(new RegisterProduct('free-product', 'Free Product', 0.00));
        $this->commandBus->send(new RegisterProduct('cheap-product', 'Cheap Product', 0.01));

        $freeProduct = $this->queryBus->send(new GetProduct('free-product'));
        $cheapProduct = $this->queryBus->send(new GetProduct('cheap-product'));

        if ($freeProduct->price !== 0.00) {
            throw new \RuntimeException('Expected free product price 0.00, got ' . $freeProduct->price);
        }

        if ($cheapProduct->price !== 0.01) {
            throw new \RuntimeException('Expected cheap product price 0.01, got ' . $cheapProduct->price);
        }

        $this->console->writeln('✓ Edge case price boundaries test passed');
    }
}
