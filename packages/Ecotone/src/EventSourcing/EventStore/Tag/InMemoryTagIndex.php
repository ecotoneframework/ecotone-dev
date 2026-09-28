<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\EventStore\Tag;

use function array_filter;
use function array_intersect_key;
use function array_slice;
use function array_values;

use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\EventSourcing\EventStore\InMemoryEventStore;
use Ecotone\EventSourcing\Tagging\MatchedEvents;
use Ecotone\Modelling\Event;

/**
 * licence Enterprise
 */
final class InMemoryTagIndex
{
    /**
     * @var array<string, array<string, array<array{stream: string, eventNo: int, tagVersion: int}>>>
     */
    private array $references = [];

    /**
     * @param array<int, array<array{name: string, value: string, sequence: int}>> $sequencedTagsPerEvent
     */
    public function record(string $streamName, int $firstEventNo, array $sequencedTagsPerEvent): void
    {
        foreach ($sequencedTagsPerEvent as $position => $tags) {
            foreach ($tags as $tag) {
                $this->references[$tag['name']][$tag['value']][] = ['stream' => $streamName, 'eventNo' => $firstEventNo + $position, 'tagVersion' => $tag['sequence']];
            }
        }
    }

    /**
     * @return Event[]
     */
    public function eventsMatching(InMemoryEventStore $eventStore, EventCriteria $criteria): array
    {
        $matched = new MatchedEvents();

        foreach ($criteria->branches() as $branch) {
            $tags = $branch->tags();
            if ($tags === []) {
                continue;
            }

            foreach ($this->referencesCarryingAll($tags) as $reference) {
                $event = $eventStore->eventAt($reference['stream'], $reference['eventNo']);
                if ($event !== null && $branch->matchesEventType($event->getEventName())) {
                    $matched->consider($reference['stream'], $reference['eventNo'], $reference['tagVersion'], $event);
                }
            }
        }

        return $matched->inTagVersionOrder();
    }

    public function deleteStream(string $streamName): void
    {
        foreach ($this->references as $tagName => $tagValues) {
            foreach ($tagValues as $tagValue => $references) {
                $this->references[$tagName][$tagValue] = array_values(array_filter(
                    $references,
                    static fn (array $reference): bool => $reference['stream'] !== $streamName
                ));
            }
        }
    }

    /**
     * @param array<array{name: string, value: string}> $tags
     * @return array<string, array{stream: string, eventNo: int, tagVersion: int}>
     */
    private function referencesCarryingAll(array $tags): array
    {
        $carryingAll = $this->referencesOf($tags[0]);

        foreach (array_slice($tags, 1) as $tag) {
            $carryingAll = array_intersect_key($carryingAll, $this->referencesOf($tag));
        }

        return $carryingAll;
    }

    /**
     * @param array{name: string, value: string} $tag
     * @return array<string, array{stream: string, eventNo: int, tagVersion: int}>
     */
    private function referencesOf(array $tag): array
    {
        $keyed = [];
        foreach ($this->references[$tag['name']][$tag['value']] ?? [] as $reference) {
            $keyed[$reference['stream'] . "\0" . $reference['eventNo']] = $reference;
        }

        return $keyed;
    }
}
