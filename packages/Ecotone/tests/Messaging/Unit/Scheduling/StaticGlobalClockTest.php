<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Scheduling;

use DateTimeImmutable;
use Ecotone\Messaging\Scheduling\Clock;
use Ecotone\Messaging\Scheduling\DatePoint;
use Ecotone\Messaging\Scheduling\Duration;
use Ecotone\Messaging\Scheduling\NativeClock;
use Ecotone\Messaging\Scheduling\SleepInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

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
        parent::setUp();

        Clock::resetGlobalClock();
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
        $staticClock = $this->createStaticClock('2025-08-11 16:00:00');
        $clock = new Clock($staticClock);

        $globalClock = Clock::get();

        $this->assertInstanceOf(Clock::class, $globalClock);
        $this->assertEquals('2025-08-11 16:00:00', $globalClock->now()->format('Y-m-d H:i:s'));
    }

    /**
     * @description Create static clock with given date time to mimic external PsrClockInterface implementation
     * as Ecotone's Clock dependency override.
     * @param string $currentDateTime
     * @return ClockInterface
     */
    private function createStaticClock(string $currentDateTime): ClockInterface
    {
        return new class ($currentDateTime) implements ClockInterface, SleepInterface {
            private static DateTimeImmutable $now;

            public function __construct(string $now)
            {
                self::$now = new DateTimeImmutable($now);
            }

            public function now(): DateTimeImmutable
            {
                return self::$now;
            }

            public function sleep(Duration $duration): void
            {
                self::$now = self::$now->modify("+{$duration->zeroIfNegative()->inMicroseconds()} microseconds");
            }
        };
    }
}
