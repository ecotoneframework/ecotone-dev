<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite\Test;

use DateTimeImmutable;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Test\StaticPsrClock;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * licence Apache-2.0
 * @internal
 */
final class WaitingOnTestClockTest extends TestCase
{
    public function test_a_wait_on_the_pinned_clock_runs_the_step_at_the_time_the_wait_moved_to(): void
    {
        $clock = new StaticPsrClock('2026-03-01 12:00:00');
        $ecotone = $this->bootstrap($clock);
        $seenAt = [];
        $clock->whenWaiting(function (DateTimeImmutable $now) use (&$seenAt): void {
            $seenAt[] = $now->format('H:i:s');
        });

        $ecotone->sendCommandWithRouting('order.await', 'order-1');

        $this->assertSame(['12:00:01', '12:00:03'], $seenAt);
        $this->assertEquals(new DateTimeImmutable('2026-03-01 12:00:03'), $clock->now());
    }

    public function test_a_wait_inside_the_step_moves_the_time_without_running_the_step_again(): void
    {
        $clock = new StaticPsrClock('2026-03-01 12:00:00');
        $ecotone = $this->bootstrap($clock);
        $seenAt = [];
        $clock->whenWaiting(function (DateTimeImmutable $now) use (&$seenAt, $ecotone): void {
            $seenAt[] = $now->format('H:i:s');
            if (count($seenAt) === 1) {
                $ecotone->sendCommandWithRouting('order.recheck', 'order-1');
            }
        });

        $ecotone->sendCommandWithRouting('order.await', 'order-1');

        $this->assertSame(['12:00:01', '12:00:08'], $seenAt);
        $this->assertEquals(new DateTimeImmutable('2026-03-01 12:00:08'), $clock->now());
    }

    public function test_a_step_on_a_clock_that_reads_the_system_time_is_refused(): void
    {
        $clock = new StaticPsrClock();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('StaticPsrClock::whenWaiting() runs its step each time a wait moves the pinned time forward, but this clock reads the system time and waits for real, so the step would never run. Pin the clock first: construct it with a time, as new StaticPsrClock(\'2026-03-01 12:00:00\') or call setCurrentTime() on it.');

        $clock->whenWaiting(static function (DateTimeImmutable $now): void {
        });
    }

    private function bootstrap(StaticPsrClock $clock): FlowTestSupport
    {
        $handler = new class () {
            #[CommandHandler('order.await')]
            public function await(string $orderId, EcotoneClockInterface $clock): void
            {
                $clock->sleep(Duration::seconds(1));
                $clock->sleep(Duration::seconds(2));
            }

            #[CommandHandler('order.recheck')]
            public function recheck(string $orderId, EcotoneClockInterface $clock): void
            {
                $clock->sleep(Duration::seconds(5));
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, ClockInterface::class => $clock],
            enabledModules: [],
        );
    }
}
