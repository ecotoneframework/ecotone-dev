<?php

namespace Test\Ecotone\Lite;

use Ecotone\Lite\EcotoneMinimal;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Modelling\Fixture\Order\ChannelConfiguration;
use Test\Ecotone\Modelling\Fixture\Order\OrderService;
use Test\Ecotone\Modelling\Fixture\Order\PlaceOrder;

final class EcotoneMinimalTest extends TestCase
{
    public function test_bootstraping_ecotone_minimal_with_given_set_of_classes()
    {
        $ecotoneTestSupport = EcotoneMinimal::boostrapWithMessageHandlers([OrderService::class, ChannelConfiguration::class], [new OrderService()]);

        $orderId = "someId";
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));
        $this->assertEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));

        $ecotoneTestSupport->run("orders");
        $this->assertNotEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_bootstraping_ecotone_minimal_with_namespace()
    {
        $ecotoneTestSupport = EcotoneMinimal::boostrapWithMessageHandlers([], [new OrderService()], ServiceConfiguration::createWithDefaults()->withNamespaces(["Test\Ecotone\Modelling\Fixture\Order"]), pathToRootCatalog: __DIR__ . '/../../');

        $orderId = "someId";
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));
        $this->assertEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));

        $ecotoneTestSupport->run("orders");
        $this->assertNotEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));
    }
}
