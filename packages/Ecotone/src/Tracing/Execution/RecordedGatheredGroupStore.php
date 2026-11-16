<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Closure;
use Ecotone\Api\Messaging\CollectedReleaseReason;
use Ecotone\Api\Messaging\GatheredReleaseReason;
use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Handler\Gathering\GatheredGroupStore;
use Ecotone\Messaging\Handler\Gathering\GatheringHeaders;
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
        $this->recorded('collect a message for ' . $endpointId, $groupKey === null ? [] : [ExecutionHint::detail(ExecutionHint::GROUP, 'key ' . $groupKey)], fn () => $this->store->collectMessage($endpointId, $groupKey, $gatherChannelName, $maxSize, $message, $deadline, $whenOpened, $whenReleased));
    }

    public function openGroup(string $groupId, string $endpointId, string $gatherChannelName, int $size, Message $original, ?int $deadline, callable $whenOpened, ?string $groupKey): void
    {
        $this->recorded('open gathering group of ' . ($size === self::UNKNOWN_SIZE ? 'an unknown number of' : $size) . ' parts', [ExecutionHint::detail(ExecutionHint::GROUP, $groupId)], fn () => $this->store->openGroup($groupId, $endpointId, $gatherChannelName, $size, $original, $deadline, $whenOpened, $groupKey));
    }

    public function completePart(string $groupId, int $partNumber, Message $report, callable $whenComplete): void
    {
        $size = $report->getHeaders()->containsKey(GatheringHeaders::SIZE) ? ' of ' . $report->getHeaders()->get(GatheringHeaders::SIZE) : '';
        $step = $this->tracer->startStep('record part ' . $partNumber . $size . ' in ' . ExecutionRecordingTracer::shortClassOf($this->store::class), hints: [ExecutionHint::detail(ExecutionHint::GROUP, $groupId)]);
        $outcome = [ExecutionHint::critical(ExecutionHint::RESULT, 'group waits for its other parts, nothing handed over, this run ends here')];
        try {
            $this->store->completePart($groupId, $partNumber, $report, function (mixed ...$arguments) use ($whenComplete, &$outcome): void {
                $outcome = [ExecutionHint::critical(ExecutionHint::RESULT, 'all ' . count($arguments[1] ?? []) . ' parts in, group released'), ExecutionHint::critical(ExecutionHint::RUNS, 'gatherer called back in this run')];
                $whenComplete(...$arguments);
            });
        } catch (Throwable $failure) {
            if ($step !== null) {
                $this->tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $this->tracer->endStep($step, '', $outcome);
        }
    }

    public function gatherChannelNameFor(string $groupId): string
    {
        return $this->store->gatherChannelNameFor($groupId);
    }

    public function releaseGroup(string $groupId, callable $whenReleased, GatheredReleaseReason|CollectedReleaseReason|null $releaseReason): void
    {
        $this->recorded('release group in ' . ExecutionRecordingTracer::shortClassOf($this->store::class), array_values(array_filter([ExecutionHint::detail(ExecutionHint::GROUP, $groupId), $releaseReason === null ? null : ExecutionHint::critical(ExecutionHint::CHOSEN_BY, $releaseReason->value)])), fn () => $this->store->releaseGroup($groupId, fn (mixed ...$arguments) => $this->recorded('call the gatherer back', [ExecutionHint::critical(ExecutionHint::RUNS, 'this consumer run')], fn () => $whenReleased(...$arguments)), $releaseReason));
    }

    public function discard(string $groupId): void
    {
        $this->recorded('discard gathering group', [ExecutionHint::detail(ExecutionHint::GROUP, $groupId)], fn () => $this->store->discard($groupId));
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

    /**
     * @param list<ExecutionHint> $hints
     */
    private function recorded(string $name, array $hints, Closure $call): void
    {
        $step = $this->tracer->startStep($name, hints: $hints);
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
