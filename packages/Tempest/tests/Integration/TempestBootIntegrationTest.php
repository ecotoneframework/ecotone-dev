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
use Test\Ecotone\Tempest\Fixture\BusinessInterface\TicketApi;
use Test\Ecotone\Tempest\Fixture\BusinessInterface\CreateTicketCommand;
use Test\Ecotone\Tempest\Fixture\BusinessInterface\GetTicketQuery;
use Test\Ecotone\Tempest\Fixture\BusinessInterface\Ticket;
use Test\Ecotone\Tempest\Fixture\BusinessInterface\TicketService;

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
            ...EcotoneTempestConfiguration::getDiscoveryPaths(),
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

        if (!$this->container->has(TicketService::class)) {
            $this->container->singleton(TicketService::class, new TicketService());
        }

        // Reset test data
        OrderService::reset();
        ProductService::reset();
        TicketService::reset();
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

    public function test_business_interface_is_available(): void
    {
        $this->assertTrue($this->container->has(TicketApi::class));
        $this->assertInstanceOf(
            TicketApi::class,
            $this->container->get(TicketApi::class)
        );
    }

    public function test_business_interface_create_ticket(): void
    {
        /** @var TicketApi $ticketApi */
        $ticketApi = $this->container->get(TicketApi::class);

        $command = new CreateTicketCommand('Bug Report', 'Application crashes on startup', 'high');
        $ticketApi->createTicket($command);

        $tickets = TicketService::getTickets();
        $this->assertCount(1, $tickets);

        $ticket = array_values($tickets)[0];
        $this->assertInstanceOf(Ticket::class, $ticket);
        $this->assertEquals('Bug Report', $ticket->title);
        $this->assertEquals('Application crashes on startup', $ticket->description);
        $this->assertEquals('high', $ticket->priority);
        $this->assertFalse($ticket->isClosed);
    }

    public function test_business_interface_get_ticket(): void
    {
        /** @var TicketApi $ticketApi */
        $ticketApi = $this->container->get(TicketApi::class);

        // Create a ticket first
        $command = new CreateTicketCommand('Feature Request', 'Add dark mode', 'low');
        $ticketApi->createTicket($command);

        // Get the ticket ID
        $tickets = TicketService::getTickets();
        $ticketId = array_keys($tickets)[0];

        // Query for the ticket using Business Interface
        $query = new GetTicketQuery($ticketId);
        $ticket = $ticketApi->getTicket($query);

        $this->assertInstanceOf(Ticket::class, $ticket);
        $this->assertEquals('Feature Request', $ticket->title);
        $this->assertEquals('Add dark mode', $ticket->description);
        $this->assertEquals('low', $ticket->priority);
    }

    public function test_business_interface_close_ticket(): void
    {
        /** @var TicketApi $ticketApi */
        $ticketApi = $this->container->get(TicketApi::class);

        // Create a ticket first
        $command = new CreateTicketCommand('Support Request', 'Password reset needed');
        $ticketApi->createTicket($command);

        // Get the ticket ID
        $tickets = TicketService::getTickets();
        $ticketId = array_keys($tickets)[0];

        // Close the ticket using Business Interface
        $ticketApi->closeTicket($ticketId);

        // Verify the ticket is closed
        $ticket = $ticketApi->getTicket(new GetTicketQuery($ticketId));
        $this->assertTrue($ticket->isClosed);
    }

    public function test_business_interface_list_all_tickets(): void
    {
        /** @var TicketApi $ticketApi */
        $ticketApi = $this->container->get(TicketApi::class);

        // Create multiple tickets
        $ticketApi->createTicket(new CreateTicketCommand('Ticket 1', 'Description 1', 'high'));
        $ticketApi->createTicket(new CreateTicketCommand('Ticket 2', 'Description 2', 'normal'));
        $ticketApi->createTicket(new CreateTicketCommand('Ticket 3', 'Description 3', 'low'));

        // List all tickets using Business Interface
        $allTickets = $ticketApi->listAllTickets();

        $this->assertCount(3, $allTickets);
        $this->assertContainsOnlyInstancesOf(Ticket::class, $allTickets);

        $titles = array_map(fn(Ticket $ticket) => $ticket->title, $allTickets);
        $this->assertContains('Ticket 1', $titles);
        $this->assertContains('Ticket 2', $titles);
        $this->assertContains('Ticket 3', $titles);
    }

    public function test_business_interface_edge_case_empty_ticket_list(): void
    {
        /** @var TicketApi $ticketApi */
        $ticketApi = $this->container->get(TicketApi::class);

        // List tickets when none exist
        $allTickets = $ticketApi->listAllTickets();

        $this->assertIsArray($allTickets);
        $this->assertCount(0, $allTickets);
    }
}
