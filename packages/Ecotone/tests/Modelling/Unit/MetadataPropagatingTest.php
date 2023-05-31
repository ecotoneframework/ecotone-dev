<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Unit;

use Ecotone\Lite\EcotoneLite;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService;

final class MetadataPropagatingTest extends TestCase
{
    public function test_propagating_headers_to_all_published_events()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class],
            [new OrderService()]
        );

        $ecotoneTestSupport->sendCommandWithRoutingKey(
            'placeOrder',
            metadata: [
                'userId' => '123'
            ]
        );

        $notifications = $ecotoneTestSupport->sendQueryWithRouting('getAllNotificationHeaders');
        $this->assertCount(2, $notifications);
        $this->assertEquals('123', $notifications[0]['userId']);
        $this->assertEquals('123', $notifications[1]['userId']);
    }
}