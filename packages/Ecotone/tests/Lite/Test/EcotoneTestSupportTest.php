<?php

namespace Test\Ecotone\Lite\Test;

use Ecotone\Lite\Test\EcotoneTestSupport;
use Ecotone\Lite\Test\TestConfiguration;
use Ecotone\Lite\Test\TestSupportGateway;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\DestinationResolutionException;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Modelling\Fixture\Order\ChannelConfiguration;
use Test\Ecotone\Modelling\Fixture\Order\OrderService;
use Test\Ecotone\Modelling\Fixture\Order\OrderWasPlaced;
use Test\Ecotone\Modelling\Fixture\Order\PlaceOrder;

final class EcotoneTestSupportTest extends TestCase
{
    public function test_bootstraping_ecotone_minimal_with_given_set_of_classes()
    {
        $ecotoneTestSupport = EcotoneTestSupport::boostrapWithMessageHandlers(
            [OrderService::class, ChannelConfiguration::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment("test"),
        );

        $orderId = "someId";
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));

        $this->assertNotEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_bootstraping_ecotone_minimal_with_namespace()
    {
        $ecotoneTestSupport = EcotoneTestSupport::boostrapWithMessageHandlers(
            [],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment("test")
                ->withNamespaces(["Test\Ecotone\Modelling\Fixture\Order"]),
            pathToRootCatalog: __DIR__ . '/../../'
        );

        $orderId = "someId";
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));

        $this->assertNotEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_bootstraping_ecotone_minimal_with_given_set_of_classes_and_asynchronous_module()
    {
        $ecotoneTestSupport = EcotoneTestSupport::boostrapWithMessageHandlers(
            [OrderService::class, ChannelConfiguration::class],
            [new OrderService()],
            enableModulePackages: [ModulePackageList::ASYNCHRONOUS_PACKAGE]
        );

        $orderId = "someId";
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));
        $this->assertEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));

        $ecotoneTestSupport->run("orders");
        $this->assertNotEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_collecting_sent_events()
    {
        $ecotoneTestSupport = EcotoneTestSupport::boostrapWithMessageHandlers(
            [OrderService::class, ChannelConfiguration::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment("test"),
        );

        $orderId = "someId";
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));

        $testSupportGateway = $ecotoneTestSupport->getTestSupportGateway();

        $this->assertEquals([new OrderWasPlaced($orderId)], $testSupportGateway->getPublishedEvents());
        $this->assertEmpty($testSupportGateway->getPublishedEvents());
    }

    public function test_collecting_sent_event_messages()
    {
        $ecotoneTestSupport = EcotoneTestSupport::boostrapWithMessageHandlers(
            [OrderService::class, ChannelConfiguration::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment("test"),
        );

        $orderId = "someId";
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));

        $testSupportGateway = $ecotoneTestSupport->getTestSupportGateway();

        $this->assertEquals(new OrderWasPlaced($orderId), $testSupportGateway->getPublishedEventMessages()[0]->getPayload());
        $this->assertEmpty($testSupportGateway->getPublishedEventMessages());
    }

    public function test_collecting_sent_commands()
    {
        $ecotoneTestSupport = EcotoneTestSupport::boostrapWithMessageHandlers(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
        );

        $ecotoneTestSupport->getEventBus()->publish(new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderWasPlaced());

        $testSupportGateway = $ecotoneTestSupport->getTestSupportGateway();

        $this->assertEquals([[]], $testSupportGateway->getSentCommands());
        $this->assertEmpty($testSupportGateway->getSentCommands());
    }

    public function test_collecting_sent_command_messages()
    {
        $ecotoneTestSupport = EcotoneTestSupport::boostrapWithMessageHandlers(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
        );

        $ecotoneTestSupport->getEventBus()->publish(new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderWasPlaced());

        $testSupportGateway = $ecotoneTestSupport->getTestSupportGateway();

        $this->assertEquals([], $testSupportGateway->getSentCommandMessages()[0]->getPayload());
        $this->assertEmpty($testSupportGateway->getSentCommandMessages());
    }

    public function test_not_command_bus_failing_in_test_mode_when_no_routing_command_found()
    {
        $ecotoneTestSupport = EcotoneTestSupport::boostrapWithMessageHandlers(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment("test")
                ->withExtensionObjects([
                    TestConfiguration::createWithDefaults()->withFailOnCommandHandlerNotFound(false)
                ]),
        );

        $command = new PlaceOrder("someId");
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('basket.addItem', $command);

        $this->assertEquals([$command], $ecotoneTestSupport->getTestSupportGateway()->getSentCommands());
    }

    public function test_failing_command_bus_in_test_mode_when_no_routing_command_found()
    {
        $ecotoneTestSupport = EcotoneTestSupport::boostrapWithMessageHandlers(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment("test")
                ->withExtensionObjects([
                    TestConfiguration::createWithDefaults()->withFailOnCommandHandlerNotFound(true)
                ]),
        );

        $this->expectException(DestinationResolutionException::class);

        $ecotoneTestSupport->getCommandBus()->sendWithRouting('basket.addItem', new PlaceOrder("someId"));
    }

    public function test_not_query_bus_failing_in_test_mode_when_no_routing_command_found()
    {
        $ecotoneTestSupport = EcotoneTestSupport::boostrapWithMessageHandlers(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment("test")
                ->withExtensionObjects([
                    TestConfiguration::createWithDefaults()->withFailOnQueryHandlerNotFound(false)
                ]),
        );

        $ecotoneTestSupport->getQueryBus()->sendWithRouting('basket.getItem', new \stdClass());

        $this->assertEquals([new \stdClass()], $ecotoneTestSupport->getTestSupportGateway()->getSentQueries());
    }

    public function test_failing_query_bus_in_test_mode_when_no_routing_command_found()
    {
        $ecotoneTestSupport = EcotoneTestSupport::boostrapWithMessageHandlers(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment("test")
                ->withExtensionObjects([
                    TestConfiguration::createWithDefaults()->withFailOnQueryHandlerNotFound(true)
                ]),
        );

        $this->expectException(DestinationResolutionException::class);

        $ecotoneTestSupport->getQueryBus()->sendWithRouting('basket.addItem', new PlaceOrder("someId"));
    }
}
