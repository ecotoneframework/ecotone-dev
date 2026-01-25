<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Scheduling;

use Ecotone\Messaging\Scheduling\Clock;
use Ecotone\Messaging\Scheduling\DatePoint;
use Ecotone\Messaging\Scheduling\NativeClock;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\Scheduling\StaticPsrClock;

/**
 * Class StaticGlobalClockTest
 * @package Test\Ecotone\Messaging\Unit\Scheduling
 * @author JB Cagumbay <cagumbay.jb@gmail.com>
 *
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class StaticGlobalClockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::tearDown();
        Clock::resetToNativeClock();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Clock::resetToNativeClock();
    }

    public function test_when_clock_is_not_instantiated_returns_default_native_clock()
    {
        $now = new DatePoint('now');
        $globalClock = Clock::get();

        $this->assertInstanceOf(NativeClock::class, $globalClock);
        $this->assertEquals($now->format('Y-m-d H:i:s'), $globalClock->now()->format('Y-m-d H:i:s'));
    }

    public function test_when_clock_is_not_instantiated_with_null_internal_clock_returns_default_native_clock()
    {
        $now = new DatePoint('now');
        $clock = new Clock();
        $globalClock = Clock::get();

        $this->assertInstanceOf(NativeClock::class, $globalClock);
        $this->assertEquals($now->format('Y-m-d H:i:s'), $globalClock->now()->format('Y-m-d H:i:s'));
    }

    public function test_when_clock_is_not_instantiated_with_not_null_internal_clock_returns_ecotone_clock()
    {
        $clock = new Clock(new StaticPsrClock('2025-08-11 16:00:00'));

        $globalClock = Clock::get();

        $this->assertInstanceOf(Clock::class, $globalClock);
        $this->assertEquals('2025-08-11 16:00:00', $globalClock->now()->format('Y-m-d H:i:s'));
    }
}
