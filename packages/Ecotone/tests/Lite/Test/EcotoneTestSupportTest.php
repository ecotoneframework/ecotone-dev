<?php

namespace Test\Ecotone\Lite\Test;

use Ecotone\Lite\Test\EcotoneTestSupport;
use Ecotone\Lite\Test\TestSupportGateway;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
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
}
