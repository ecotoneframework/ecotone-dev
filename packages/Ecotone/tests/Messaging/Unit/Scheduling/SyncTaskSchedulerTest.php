<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Scheduling;

use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Scheduling\PeriodicTrigger;
use Ecotone\Messaging\Scheduling\StubUTCClock;
use Ecotone\Messaging\Scheduling\SyncTaskScheduler;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\Scheduling\StubTaskExecutor;

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
class SyncTaskSchedulerTest extends TestCase
{
    public function test_when_first_time_called_then_it_should_trigger_immediately()
    {
        $clock = StubUTCClock::createWithCurrentTime('2016-01-01 12:00:00');
        $syncTaskExecutor = SyncTaskScheduler::createWithEmptyTriggerContext($clock, PollingMetadata::create('test'));
        $taskExecutor = StubTaskExecutor::create();

        $syncTaskExecutor->schedule($taskExecutor, PeriodicTrigger::create(1, 0));

        $this->assertTrue($taskExecutor->wasCalled());
    }

    public function test_when_calling_second_time_it_should_wait_fixed_rate()
    {
        $clock = StubUTCClock::createWithCurrentTime('2016-01-01 12:00:00');
        $syncTaskExecutor = SyncTaskScheduler::createWithEmptyTriggerContext($clock, PollingMetadata::create('test'));
        $taskExecutor = StubTaskExecutor::create();

        $trigger = PeriodicTrigger::create(1000, 0);
        $syncTaskExecutor->schedule($taskExecutor, $trigger);

        $this->assertEquals(1, $taskExecutor->getCalledTimes(), 'Was called too many times');

        $clock->changeCurrentTime('2016-01-01 12:00:01');
        $syncTaskExecutor->schedule($taskExecutor, $trigger);

        $this->assertEquals(2, $taskExecutor->getCalledTimes());
    }
}
