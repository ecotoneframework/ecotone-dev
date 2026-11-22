<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Scheduling;

use DateInterval;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Messaging\Support\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DurationTest extends TestCase
{
    public function test_duration_is_the_sum_of_its_named_units(): void
    {
        $duration = new Duration(milliseconds: 5, seconds: 4, minutes: 3, hours: 2, days: 1, microseconds: 6);

        $this->assertSame(93_784_005_006, $duration->inMicroseconds());
    }

    public function test_first_positional_unit_is_milliseconds(): void
    {
        $this->assertSame(500, (new Duration(500))->inMilliseconds());
    }

    public function test_duration_is_created_from_hours_and_days(): void
    {
        $this->assertSame(5_400_000, Duration::hours(1.5)->inMilliseconds());
        $this->assertSame(172_800, Duration::days(2)->inSeconds());
    }

    public function test_duration_without_units_is_zero(): void
    {
        $this->assertTrue((new Duration())->isZero());
    }

    public function test_duration_is_created_from_a_date_interval(): void
    {
        $duration = Duration::fromDateInterval(new DateInterval('P1DT2H3M4S'));

        $this->assertSame(93_784, $duration->inSeconds());
    }

    public function test_date_interval_with_months_or_years_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duration can not be created from a DateInterval of 0 years and 1 months, as their length depends on the calendar. Pass a DateInterval made of days, hours, minutes and seconds, or build the Duration from those units: new Duration(days: 30).');

        Duration::fromDateInterval(new DateInterval('P1M'));
    }
}
