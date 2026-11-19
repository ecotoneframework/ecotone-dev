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
        $this->recorded(name: 'collect a message for ' . $endpointId, hints: $groupKey === null ? [] : [ExecutionHint::detail(ExecutionHint::GROUP, 'key ' . $groupKey)], call: fn () => $this->store->collectMessage($endpointId, $groupKey, $gatherChannelName, $maxSize, $message, $deadline, $whenOpened, $whenReleased));
    }

    public function openGroup(string $groupId, string $endpointId, string $gatherChannelName, int $size, Message $original, ?int $deadline, callable $whenOpened, ?string $groupKey): void
    {
        $this->recorded(name: 'open gathering group of ' . ($size === self::UNKNOWN_SIZE ? 'an unknown number of' : $size) . ' parts', hints: [ExecutionHint::detail(ExecutionHint::GROUP, $groupId)], call: fn () => $this->store->openGroup($groupId, $endpointId, $gatherChannelName, $size, $original, $deadline, $whenOpened, $groupKey));
    }

    public function completePart(string $groupId, int $partNumber, Message $report, callable $whenComplete): void
    {
        $size = $report->getHeaders()->containsKey(GatheringHeaders::SIZE) ? ' of ' . $report->getHeaders()->get(GatheringHeaders::SIZE) : '';
        $step = $this->tracer->startStep('record part ' . $partNumber . $size . ' in ' . ExecutionRecordingTracer::shortClassOf($this->store::class), hints: [ExecutionHint::detail(ExecutionHint::GROUP, $groupId)]);
        $released = null;
        try {
            $before = $this->store->recordedPartsOf($groupId);
            $this->store->completePart($groupId, $partNumber, $report, function (mixed ...$arguments) use ($whenComplete, &$released): void {
                $released = count($arguments[1] ?? []);
                $whenComplete(...$arguments);
            });
            $outcome = $this->outcomeOfPart(groupId: $groupId, partNumber: $partNumber, before: $before, released: $released);
        } catch (Throwable $failure) {
            if ($step !== null) {
                $this->tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $this->tracer->endStep(step: $step, outcome: '', hints: $outcome);
        }
    }

    /**
     * @param array{state: string, size: ?int, partNumbers: list<int>}|null $before
     * @return list<ExecutionHint>
     */
    private function outcomeOfPart(string $groupId, int $partNumber, ?array $before, ?int $released): array
    {
        if ($released !== null) {
            return [ExecutionHint::shownInLines(ExecutionHint::OUTCOME, 'part stored, ' . $released . ' of ' . $released . ' in, group released'), ExecutionHint::shownInLines(ExecutionHint::RUNS_IN, 'this consumer run, gatherer called back')];
        }
        $alreadyThere = $before !== null && in_array($partNumber, $before['partNumbers'], true);
        if ($before !== null && $before['state'] !== 'open') {
            return [ExecutionHint::shownInLines(ExecutionHint::OUTCOME, ($alreadyThere ? 'duplicate' : 'late') . ' part ignored, group already ' . $before['state'])];
        }
        if ($alreadyThere) {
            return [ExecutionHint::shownInLines(ExecutionHint::OUTCOME, 'duplicate part ignored')];
        }
        $after = $this->store->recordedPartsOf($groupId);
        if ($after === null || ! in_array($partNumber, $after['partNumbers'], true)) {
            return [ExecutionHint::shownInLines(ExecutionHint::OUTCOME, 'part not stored, group ' . ($after['state'] ?? 'gone'))];
        }

        return [ExecutionHint::shownInLines(ExecutionHint::OUTCOME, 'part stored, ' . count($after['partNumbers']) . ' of ' . ($after['size'] ?? '?') . ' in, group waits')];
    }

    public function gatherChannelNameFor(string $groupId): string
    {
        return $this->store->gatherChannelNameFor($groupId);
    }

    public function recordedPartsOf(string $groupId): ?array
    {
        return $this->store->recordedPartsOf($groupId);
    }

    public function releaseGroup(string $groupId, callable $whenReleased, GatheredReleaseReason|CollectedReleaseReason|null $releaseReason): void
    {
        $this->recorded(name: 'release group in ' . ExecutionRecordingTracer::shortClassOf($this->store::class), hints: array_values(array_filter([ExecutionHint::detail(ExecutionHint::GROUP, $groupId), $releaseReason === null ? null : ExecutionHint::shownInLines(ExecutionHint::CHOSEN_BY, $releaseReason->value)])), call: fn () => $this->store->releaseGroup($groupId, fn (mixed ...$arguments) => $this->recorded('call the gatherer back', [ExecutionHint::shownInLines(ExecutionHint::RUNS_IN, 'this consumer run')], fn () => $whenReleased(...$arguments)), $releaseReason));
    }

    public function discard(string $groupId): void
    {
        $this->recorded(name: 'discard gathering group', hints: [ExecutionHint::detail(ExecutionHint::GROUP, $groupId)], call: fn () => $this->store->discard($groupId));
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
