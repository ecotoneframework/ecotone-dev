<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

use function array_diff_key;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Modelling\Event;

use function is_object;
use function min;

use const PHP_INT_MAX;

/**
 * licence Enterprise
 */
final class TagResolver
{
    public function __construct(
        private readonly EventTagRegistry $eventTagRegistry,
        private readonly AggregateCounterTags $aggregateCounterTags,
    ) {
    }

    /**
     * @param array<object|array> $events
     */
    public function resolveEvents(array $events): EventsTags
    {
        $perEvent = [];
        foreach ($events as $event) {
            $perEvent[] = $this->tagsCarriedBy($event);
        }

        return new EventsTags($perEvent);
    }

    /**
     * @param array<object|array> $events
     */
    public function resolveAppend(array $events, ?AppendCondition $appendCondition): AppendedTags
    {
        $conditionVersions = [];
        foreach ($appendCondition?->expectedTagVersions() ?? [] as $expected) {
            $conditionVersions[TagKey::of($expected['name'], $expected['value'])] = $expected;
        }

        $aggregateCounterTags = $this->counterTagOfSavedAggregate($appendCondition);

        return new AppendedTags(
            $this->resolveEvents($events),
            $conditionVersions,
            $aggregateCounterTags,
            $this->aggregateTypesOfCounterTags([...$conditionVersions, ...$aggregateCounterTags]),
        );
    }

    /**
     * @return array<string, array{name: string, value: string}>
     */
    public function tagsOfCriteria(EventCriteria $criteria): array
    {
        $tags = [];
        foreach ($criteria->branches() as $branch) {
            foreach ($branch->tags() as $tag) {
                $tags[TagKey::of($tag['name'], $tag['value'])] = $tag;
            }
        }

        return $tags;
    }

    /**
     * The criterion's tags that carry a counter, so a decision folded from it is guarded by them.
     * A filter-only tag narrows the read and is never counted, so capturing its version would
     * serialise every writer that shares it.
     *
     * @return array<string, array{name: string, value: string}>
     */
    public function countedTagsOfCriteria(EventCriteria $criteria): array
    {
        $tags = [];
        foreach ($this->tagsOfCriteria($criteria) as $key => $tag) {
            if (! $this->eventTagRegistry->isFilterOnly($tag['name'])) {
                $tags[$key] = $tag;
            }
        }

        return $tags;
    }

    /**
     * The tag whose sequence positions a criterion: the first tag the index actually counts,
     * because a filter-only tag is stamped with sequence 0 and would position nothing.
     *
     * @return array{name: string, value: string}|null
     */
    public function positionTagOf(EventCriteria $branch): ?array
    {
        foreach ($branch->tags() as $tag) {
            if (! $this->eventTagRegistry->isFilterOnly($tag['name'])) {
                return $tag;
            }
        }

        return $branch->tags()[0] ?? null;
    }

    /**
     * The lower bounds that may be pushed into the index read. A bound is only safe for a tag
     * key that every branch uses as its position tag: elsewhere the key also decides whether an
     * event carries all of a branch's tags, and cutting its rows would drop matching events.
     *
     * @return array<string, int>
     */
    public function pushDownableTagSequenceLowerBounds(EventCriteria $criteria): array
    {
        $bounds = [];
        $unsafe = [];
        foreach ($criteria->branches() as $branch) {
            $positionTag = $this->positionTagOf($branch);
            $positionTagKey = $positionTag === null ? null : TagKey::of($positionTag['name'], $positionTag['value']);

            foreach ($branch->tags() as $tag) {
                $tagKey = TagKey::of($tag['name'], $tag['value']);
                if ($tagKey !== $positionTagKey || $branch->tagSequenceLowerBound() === 0) {
                    $unsafe[$tagKey] = true;
                }
            }

            if ($positionTagKey !== null && $branch->tagSequenceLowerBound() > 0) {
                $bounds[$positionTagKey] = min($bounds[$positionTagKey] ?? PHP_INT_MAX, $branch->tagSequenceLowerBound());
            }
        }

        return array_diff_key($bounds, $unsafe);
    }

    /**
     * @param Event[] $events
     * @return Event[]
     */
    public function eventsMatching(array $events, EventCriteria $criteria): array
    {
        $positionTag = $criteria->tagSequenceLowerBound() > 0 ? $this->positionTagOf($criteria) : null;
        $positionTagKey = $positionTag === null ? null : TagKey::of($positionTag['name'], $positionTag['value']);

        $matching = [];
        foreach ($events as $event) {
            if (! $criteria->matchesEventType($event->getEventName()) || ! $this->carriesAll($event, $criteria->tags())) {
                continue;
            }

            if ($positionTagKey !== null && (MatchedTagSequences::of($event, $positionTagKey) ?? 0) <= $criteria->tagSequenceLowerBound()) {
                continue;
            }

            $matching[] = $event;
        }

        return $matching;
    }

    /**
     * @param array<array{name: string, value: string}> $requiredTags
     */
    private function carriesAll(Event $event, array $requiredTags): bool
    {
        $carried = [];
        foreach ($this->tagsCarriedBy($event) as $tag) {
            $carried[TagKey::of($tag['name'], $tag['value'])] = true;
        }

        foreach ($requiredTags as $requiredTag) {
            if (! isset($carried[TagKey::of($requiredTag['name'], $requiredTag['value'])])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<array{name: string, value: string, counted: bool}>
     */
    private function tagsCarriedBy(object|array $event): array
    {
        $payload = $event instanceof Event ? $event->getPayload() : $event;
        if (! is_object($payload)) {
            return [];
        }

        $tags = [];
        foreach ($this->eventTagRegistry->tagsFor($payload) as $tag) {
            $tags[] = ['name' => $tag['name'], 'value' => $tag['value'], 'counted' => ! $this->eventTagRegistry->isFilterOnly($tag['name'])];
        }

        return $tags;
    }

    /**
     * @return array<string, array{name: string, value: string}>
     */
    private function counterTagOfSavedAggregate(?AppendCondition $appendCondition): array
    {
        if ($appendCondition === null || ! $appendCondition->hasAggregateCondition() || ! $this->aggregateCounterTags->counts($appendCondition->aggregateType())) {
            return [];
        }

        $counterTag = $this->aggregateCounterTags->counterTagOf($appendCondition->aggregateType(), $appendCondition->aggregateId());

        return [TagKey::of($counterTag['name'], $counterTag['value']) => $counterTag];
    }

    /**
     * @param array<string, array{name: string, value: string}> $tags
     * @return array<string, string>
     */
    private function aggregateTypesOfCounterTags(array $tags): array
    {
        $aggregateTypes = [];
        foreach ($tags as $key => $tag) {
            $aggregateType = $this->aggregateCounterTags->aggregateTypeCountedBy($tag['name']);
            if ($aggregateType !== null) {
                $aggregateTypes[$key] = $aggregateType;
            }
        }

        return $aggregateTypes;
    }
}
