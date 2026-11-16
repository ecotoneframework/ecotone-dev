<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Closure;
use Ecotone\Api\Messaging\CollectedReleaseReason;
use Ecotone\Api\Messaging\GatheredReleaseReason;
use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Handler\Gathering\GatheredGroupStore;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedGatheredGroupStore implements GatheredGroupStore
{
    public function __construct(private GatheredGroupStore $store, private ExecutionRecordingTracer $tracer)
    {
    }

    public function collectMessage(string $endpointId, ?string $groupKey, string $gatherChannelName, ?int $maxSize, Message $message, ?int $deadline, callable $whenOpened, callable $whenReleased): void
    {
        $this->recorded('collect a message for ' . $endpointId . ($groupKey === null ? '' : ' into group key ' . $groupKey), fn () => $this->store->collectMessage($endpointId, $groupKey, $gatherChannelName, $maxSize, $message, $deadline, $whenOpened, $whenReleased));
    }

    public function openGroup(string $groupId, string $endpointId, string $gatherChannelName, int $size, Message $original, ?int $deadline, callable $whenOpened, ?string $groupKey): void
    {
        $this->recorded('open gathering group ' . $groupId . ' of ' . ($size === self::UNKNOWN_SIZE ? 'an unknown number of' : $size) . ' parts', fn () => $this->store->openGroup($groupId, $endpointId, $gatherChannelName, $size, $original, $deadline, $whenOpened, $groupKey));
    }

    public function completePart(string $groupId, int $partNumber, Message $report, callable $whenComplete): void
    {
        $released = false;
        $this->recorded('complete part ' . $partNumber . ' of gathering group ' . $groupId . ' in ' . ExecutionRecordingTracer::shortClassOf($this->store::class), function () use ($groupId, $partNumber, $report, $whenComplete, &$released): void {
            $this->store->completePart($groupId, $partNumber, $report, function (mixed ...$arguments) use ($whenComplete, &$released): void {
                $released = true;
                $this->recorded('all parts are in, so the store releases the group and calls the gatherer back', fn () => $whenComplete(...$arguments));
            });
            if (! $released) {
                $this->tracer->recordStep('the group is not complete yet, so nothing is handed over: this process ends here and the remaining parts are awaited');
            }
        });
    }

    public function gatherChannelNameFor(string $groupId): string
    {
        return $this->store->gatherChannelNameFor($groupId);
    }

    public function releaseGroup(string $groupId, callable $whenReleased, GatheredReleaseReason|CollectedReleaseReason|null $releaseReason): void
    {
        $this->recorded('release gathering group ' . $groupId . ($releaseReason === null ? '' : ', reason ' . $releaseReason->value) . ' in ' . ExecutionRecordingTracer::shortClassOf($this->store::class), fn () => $this->store->releaseGroup($groupId, fn (mixed ...$arguments) => $this->recorded('the store releases the group and calls the gatherer back', fn () => $whenReleased(...$arguments)), $releaseReason));
    }

    public function discard(string $groupId): void
    {
        $this->recorded('discard gathering group ' . $groupId, fn () => $this->store->discard($groupId));
    }

    public function cleanupClosedGroups(int $closedAtOrBefore): void
    {
        $this->store->cleanupClosedGroups($closedAtOrBefore);
    }

    public function listOpenGroups(string $endpointId): array
    {
        return $this->store->listOpenGroups($endpointId);
    }

    public function listClosedGroups(string $endpointId): array
    {
        return $this->store->listClosedGroups($endpointId);
    }

    private function recorded(string $name, Closure $call): void
    {
        $step = $this->tracer->startStep($name);
        try {
            $call();
        } catch (Throwable $failure) {
            if ($step !== null) {
                $this->tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $this->tracer->endStep($step, '');
        }
    }
}
