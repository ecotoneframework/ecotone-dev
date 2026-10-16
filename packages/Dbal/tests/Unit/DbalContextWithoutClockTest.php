<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Unit;

use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Messaging\Scheduling\NativeClock;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DbalContextWithoutClockTest extends TestCase
{
    public function test_a_context_created_straight_from_the_connection_factory_refuses_to_hand_out_a_clock(): void
    {
        $context = (new DbalConnectionFactory('sqlite:///:memory:'))->createContext();

        self::assertInstanceOf(DbalContext::class, $context);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('This DbalContext was created straight from the connection factory, so it carries no clock to produce or consume messages with. Ecotone reaches every context through Ecotone\Dbal\DbalReconnectableConnectionFactory, which hands it the EcotoneClockInterface from the container; create the context through that wrapper, or use the context only for getDbalConnection().');

        $context->getClock();
    }

    public function test_a_context_created_through_the_ecotone_wrapper_carries_the_clock_it_was_given(): void
    {
        $clock = new NativeClock();

        $context = (new DbalReconnectableConnectionFactory(new DbalConnectionFactory('sqlite:///:memory:'), $clock))->createContext();

        self::assertInstanceOf(DbalContext::class, $context);
        self::assertSame($clock, $context->getClock());
    }
}
