<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Modelling\Event;

use function is_object;

/**
 * licence Enterprise
 */
final class TagResolver
{
    public function __construct(
        private readonly EventTagRegistry $eventTagRegistry,
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

        return new AppendedTags($this->resolveEvents($events), $conditionVersions);
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
     * @param Event[] $events
     * @return Event[]
     */
    public function eventsMatching(array $events, EventCriteria $criteria): array
    {
        $matching = [];
        foreach ($events as $event) {
            if ($criteria->matchesEventType($event->getEventName()) && $this->carriesAll($event, $criteria->tags())) {
                $matching[] = $event;
            }
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
}
