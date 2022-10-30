<?php

namespace Test\Ecotone\Lite;

use Ecotone\Lite\EcotoneMinimal;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Modelling\Fixture\Order\ChannelConfiguration;
use Test\Ecotone\Modelling\Fixture\Order\OrderService;
use Test\Ecotone\Modelling\Fixture\Order\PlaceOrder;

final class EcotoneMinimalTest extends TestCase
{
    public function test_running_application_with_external_service()
    {
//        $start = microtime(true);
        $ecotoneTestSupport = EcotoneMinimal::boostrapWithMessageHandlers([OrderService::class, ChannelConfiguration::class], [new OrderService()]);

        $orderId = "someId";
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));
        $this->assertEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));

        $ecotoneTestSupport->run("orders");
        $this->assertNotEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));

//        $end = microtime(true);
//
//        echo ($end - $start) * 1000;
    }
}
