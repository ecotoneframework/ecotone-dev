<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Scheduling;

use Ecotone\Messaging\Scheduling\Clock;
use Ecotone\Messaging\Scheduling\Duration;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\StaticClock;

/**
 * Class SyncTaskSchedulerTest
 * @package Test\Ecotone\Messaging\Unit\Scheduling
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 *
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class ClockTest extends TestCase
{
    public function test_when_given_psr_clock_instance_with_sleep_support_it_increase_the_clock()
    {
        $clock = new Clock(new StaticClock('2025-08-11 16:00:00'));
        $clock->sleep(Duration::seconds(30));

        $this->assertEquals('2025-08-11 16:00:30', $clock->now()->format('Y-m-d H:i:s'));
    }
}
