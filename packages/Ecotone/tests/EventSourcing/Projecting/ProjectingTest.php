<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace EventSourcing\Projecting;

use PHPUnit\Framework\TestCase;

class ProjectingTest extends TestCase
{
    public function testProjecting(): void
    {
        self::assertEquals(["1" => ["orderId" => "1", "orderName" => "name", "isCanceled" => true]], $projector->getOrders());
    }
}