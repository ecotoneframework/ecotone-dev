<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\EventStore\Tag;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\EventSourcing\Tagging\EventTagRegistry;
use Ecotone\Modelling\Event;

use function array_intersect_key;
use function array_map;
use function array_values;
use function is_object;
use function ksort;
use function uasort;

/**
 * licence Enterprise
 */
final class InMemoryTagConditionalStore implements InMemoryTagCollaborator
{
    /**
     * @var array<string, array<string, array<array{stream: string, eventNo: int, tagVersion: int}>>>
     */
    private array $tagIndex = [];

    /**
     * @var array<string, int>
     */
    private array $tagVersions = [];

    public function __construct(
        private readonly EventTagRegistry $eventTagRegistry,
    ) {
    }

    public function loadByCriteria(InMemoryStreamAccess $streamAccess, EventCriteria $criteria): LoadedEvents
    {
        $branches = $criteria->branches();

        $capturedTags = [];
        foreach ($branches as $criterion) {
            foreach ($criterion->tags() as $tag) {
                $key = $this->tagVersionKey($tag['name'], $tag['value']);
                if (! isset($capturedTags[$key])) {
                    $capturedTags[$key] = [
                        'name' => $tag['name'],
                        'value' => $tag['value'],
                        'expectedVersion' => $this->currentTagVersion($tag['name'], $tag['value']),
                    ];
                }
            }
        }

        $matched = [];
        foreach ($branches as $criterion) {
            $tags = $criterion->tags();
            if ($tags === []) {
                continue;
            }

            $refSets = null;
            foreach ($tags as $tag) {
                $refs = $this->tagIndex[$tag['name']][$tag['value']] ?? [];
                $keyed = [];
                foreach ($refs as $ref) {
                    $keyed[$ref['stream'] . "\0" . $ref['eventNo']] = $ref;
                }

                $refSets = $refSets === null ? $keyed : array_intersect_key($refSets, $keyed);
            }

            $primaryTag = $tags[0];
            $primaryRefsByKey = [];
            foreach ($this->tagIndex[$primaryTag['name']][$primaryTag['value']] ?? [] as $ref) {
                $primaryRefsByKey[$ref['stream'] . "\0" . $ref['eventNo']] = $ref;
            }

            foreach ($refSets as $refKey => $ref) {
                $event = $streamAccess->eventAt($ref['stream'], $ref['eventNo']);
                if ($event === null || ! $criterion->matchesEventType($event->getEventName())) {
                    continue;
                }

                $tagVersion = $primaryRefsByKey[$refKey]['tagVersion'] ?? $ref['tagVersion'];

                if (! isset($matched[$refKey]) || $matched[$refKey]['tagVersion'] > $tagVersion) {
                    $matched[$refKey] = [
                        'eventNo' => $ref['eventNo'],
                        'tagVersion' => $tagVersion,
                        'event' => $event,
                    ];
                }
            }
        }

        uasort($matched, static function (array $a, array $b): int {
            return $a['tagVersion'] <=> $b['tagVersion'] ?: $a['eventNo'] <=> $b['eventNo'];
        });

        $events = array_values(array_map(static fn (array $match) => $match['event'], $matched));

        return new LoadedEvents($events, AppendCondition::fromCapturedVersions(array_values($capturedTags)));
    }

    public function appendEventsWithTagCondition(InMemoryStreamAccess $streamAccess, string $streamName, array $events, ?AppendCondition $appendCondition): void
    {
        $perEventTags = [];
        $tagsInvolved = [];
        foreach ($events as $event) {
            $payload = $event->getPayload();
            $tags = is_object($payload) ? $this->eventTagRegistry->tagsFor($payload) : [];
            $perEventTags[] = $tags;
            foreach ($tags as $tag) {
                if ($this->eventTagRegistry->isFilterOnly($tag['name'])) {
                    continue;
                }

                $tagsInvolved[$this->tagVersionKey($tag['name'], $tag['value'])] = $tag;
            }
        }

        if ($appendCondition !== null) {
            foreach ($appendCondition->expectedTagVersions() as $expected) {
                $current = $this->currentTagVersion($expected['name'], $expected['value']);
                if ($current !== $expected['expectedVersion']) {
                    throw DecisionModelConcurrencyException::forConflict(
                        $expected['name'],
                        $expected['value'],
                        $expected['expectedVersion'],
                        $current,
                    );
                }

                $tagsInvolved[$this->tagVersionKey($expected['name'], $expected['value'])] = [
                    'name' => $expected['name'],
                    'value' => $expected['value'],
                ];
            }
        }

        ksort($tagsInvolved);
        $newVersions = [];
        foreach ($tagsInvolved as $key => $tag) {
            $newVersions[$key] = $this->bumpTagVersion($tag['name'], $tag['value']);
        }

        foreach ($events as $i => $event) {
            $eventNo = $streamAccess->appendEvent($streamName, $event);

            foreach ($perEventTags[$i] as $tag) {
                $key = $this->tagVersionKey($tag['name'], $tag['value']);
                $this->tagIndex[$tag['name']][$tag['value']][] = [
                    'stream' => $streamName,
                    'eventNo' => $eventNo,
                    'tagVersion' => $this->eventTagRegistry->isFilterOnly($tag['name']) ? 0 : $newVersions[$key],
                ];
            }
        }
    }

    public function deleteTagIndexFor(string $streamName): void
    {
        foreach ($this->tagIndex as $tagName => $tagValues) {
            foreach ($tagValues as $tagValue => $refs) {
                $this->tagIndex[$tagName][$tagValue] = array_values(array_filter(
                    $refs,
                    static fn (array $ref): bool => $ref['stream'] !== $streamName
                ));
            }
        }
    }

    private function tagVersionKey(string $name, string $value): string
    {
        return $name . "\0" . $value;
    }

    private function currentTagVersion(string $name, string $value): int
    {
        return $this->tagVersions[$this->tagVersionKey($name, $value)] ?? 0;
    }

    private function bumpTagVersion(string $name, string $value): int
    {
        $key = $this->tagVersionKey($name, $value);
        $this->tagVersions[$key] = ($this->tagVersions[$key] ?? 0) + 1;

        return $this->tagVersions[$key];
    }
}