<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\EventStore;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\Tagging\EventTagRegistry;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\Event;

use function array_intersect_key;
use function array_map;
use function array_values;
use function explode;
use function in_array;
use function is_array;
use function is_object;
use function ksort;
use function preg_match;
use function uasort;

/**
 * In-memory implementation of EventStore for testing purposes
 * licence Apache-2.0
 */
final class InMemoryEventStore implements EventStore, AggregateEventStore
{
    private array $streams = [];

    /**
     * @var array<string, array<string, array<array{stream: string, eventNo: int, tagVersion: int}>>>
     */
    private array $tagIndex = [];

    /**
     * @var array<string, int>
     */
    private array $tagVersions = [];

    private readonly EventTagRegistry $eventTagRegistry;

    public function __construct(?EventTagRegistry $eventTagRegistry = null)
    {
        $this->eventTagRegistry = $eventTagRegistry ?? EventTagRegistry::createEmpty();
    }

    public function create(string $streamName, array $streamEvents = [], array $streamMetadata = []): void
    {
        if (isset($this->streams[$streamName])) {
            throw new InvalidArgumentException("Stream {$streamName} already exists");
        }

        $this->streams[$streamName] = [
            'events' => [],
            'metadata' => $streamMetadata,
        ];

        $this->doAppend($streamName, $streamEvents, null);
    }

    public function appendTo(string $streamName, array $streamEvents, ?AppendCondition $appendCondition = null): void
    {
        if (! isset($this->streams[$streamName])) {
            $this->streams[$streamName] = [
                'events' => [],
                'metadata' => [],
            ];
        }

        $this->doAppend($streamName, $streamEvents, $appendCondition);
    }

    public function loadByCriteria(EventCriteria ...$criteria): LoadedEvents
    {
        $capturedTags = [];
        foreach ($criteria as $criterion) {
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
        foreach ($criteria as $criterion) {
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
                $event = $this->streams[$ref['stream']]['events'][$ref['eventNo'] - 1] ?? null;
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

    public function delete(string $streamName): void
    {
        unset($this->streams[$streamName]);

        foreach ($this->tagIndex as $tagName => $tagValues) {
            foreach ($tagValues as $tagValue => $refs) {
                $this->tagIndex[$tagName][$tagValue] = array_values(array_filter(
                    $refs,
                    static fn (array $ref): bool => $ref['stream'] !== $streamName
                ));
            }
        }
    }

    public function hasStream(string $streamName): bool
    {
        return isset($this->streams[$streamName]);
    }

    public function load(
        string $streamName,
        int $fromNumber = 1,
        ?int $count = null,
        ?MetadataMatcher $metadataMatcher = null,
        bool $deserialize = true
    ): iterable {
        return $this->loadEvents($streamName, $fromNumber, $count, $metadataMatcher);
    }

    /**
     * @param Event[]|object[]|array[] $streamEvents
     */
    private function doAppend(string $streamName, array $streamEvents, ?AppendCondition $appendCondition): void
    {
        $events = $this->convertToEvents($streamEvents);

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

        $startingIndex = count($this->streams[$streamName]['events']);
        foreach ($events as $i => $event) {
            $this->streams[$streamName]['events'][] = $event;
            $eventNo = $startingIndex + $i + 1;

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

    public function loadAggregateEvents(
        string $streamName,
        ?string $aggregateType,
        string $aggregateId,
        int $fromVersion = 1,
        ?int $count = null,
        array $eventNames = [],
        bool $deserialize = true
    ): iterable {
        $metadataMatcher = new MetadataMatcher();
        if ($aggregateType !== null) {
            $metadataMatcher = $metadataMatcher->withMetadataMatch(MessageHeaders::EVENT_AGGREGATE_TYPE, Operator::EQUALS, $aggregateType);
        }
        $metadataMatcher = $metadataMatcher->withMetadataMatch(MessageHeaders::EVENT_AGGREGATE_ID, Operator::EQUALS, $aggregateId);
        $metadataMatcher = $metadataMatcher->withMetadataMatch(MessageHeaders::EVENT_AGGREGATE_VERSION, Operator::GREATER_THAN_EQUALS, $fromVersion);
        if ($eventNames !== []) {
            $metadataMatcher = $metadataMatcher->withMetadataMatch('event_name', Operator::IN, $eventNames, FieldType::MESSAGE_PROPERTY);
        }

        return $this->load($streamName, 1, $count, $metadataMatcher, $deserialize);
    }

    public function loadReverse(
        string $streamName,
        ?int $fromNumber = null,
        ?int $count = null,
        ?MetadataMatcher $metadataMatcher = null,
        bool $deserialize = true
    ): iterable {
        if ($fromNumber !== null && $fromNumber < 1) {
            throw new InvalidArgumentException('fromNumber must be >= 1 or null');
        }

        if ($count !== null && $count < 1) {
            throw new InvalidArgumentException('count must be >= 1 or null');
        }

        if (! isset($this->streams[$streamName])) {
            return [];
        }

        if ($metadataMatcher === null) {
            $metadataMatcher = new MetadataMatcher();
        }

        $events = $this->streams[$streamName]['events'];
        $totalEvents = count($events);

        // If fromNumber is null, start from the last event
        $startPosition = $fromNumber !== null ? $fromNumber : $totalEvents;

        $found = 0;
        $result = [];

        // Iterate in reverse order
        for ($position = $startPosition; $position >= 1; $position--) {
            $key = $position - 1;

            if (! isset($events[$key])) {
                continue;
            }

            $event = $events[$key];

            if ($this->matchesMetadata($metadataMatcher, $event->getMetadata())
                && $this->matchesEventProperty($metadataMatcher, $event)
            ) {
                ++$found;
                $result[] = $event;

                if ($found === $count) {
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Get all streams with their events and metadata
     * Used for converting to a persistent event store
     * @return array<string, array{events: Event[], metadata: array}>
     */
    public function getAllStreams(): array
    {
        return $this->streams;
    }

    /**
     * @param Event[]|object[]|array[] $events
     * @return Event[]
     */
    private function convertToEvents(array $events): array
    {
        $result = [];
        foreach ($events as $event) {
            if ($event instanceof Event) {
                $result[] = $event;
            } elseif (is_array($event)) {
                // Arrays are not supported directly, they need to be wrapped in an object
                $result[] = Event::createWithType('array', $event);
            } else {
                $result[] = Event::create($event);
            }
        }
        return $result;
    }

    private function loadEvents(
        string $streamName,
        int $fromNumber = 1,
        ?int $count = null,
        ?MetadataMatcher $metadataMatcher = null,
    ): iterable {
        if ($fromNumber < 1) {
            throw new InvalidArgumentException('fromNumber must be >= 1');
        }

        if ($count !== null && $count < 1) {
            throw new InvalidArgumentException('count must be >= 1 or null');
        }

        if (! isset($this->streams[$streamName])) {
            return [];
        }

        if ($metadataMatcher === null) {
            $metadataMatcher = new MetadataMatcher();
        }

        $found = 0;
        $result = [];

        foreach ($this->streams[$streamName]['events'] as $key => $event) {
            $position = $key + 1;

            if ($position >= $fromNumber
                && $this->matchesMetadata($metadataMatcher, $event->getMetadata())
                && $this->matchesEventProperty($metadataMatcher, $event)
            ) {
                ++$found;
                $result[] = $event;

                if ($found === $count) {
                    break;
                }
            }
        }

        return $result;
    }

    private function matchesMetadata(MetadataMatcher $metadataMatcher, array $metadata): bool
    {
        foreach ($metadataMatcher->data() as $match) {
            if ($match['fieldType'] !== FieldType::METADATA) {
                continue;
            }

            $field = $match['field'];

            if (! isset($metadata[$field])) {
                return false;
            }

            if (! $this->match($match['operator'], $metadata[$field], $match['value'])) {
                return false;
            }
        }

        return true;
    }

    private function matchesEventProperty(MetadataMatcher $metadataMatcher, Event $event): bool
    {
        foreach ($metadataMatcher->data() as $match) {
            if ($match['fieldType'] !== FieldType::MESSAGE_PROPERTY) {
                continue;
            }

            $value = $this->getEventPropertyValue($event, $match['field']);

            if (! $this->match($match['operator'], $value, $match['value'])) {
                return false;
            }
        }

        return true;
    }

    private function getEventPropertyValue(Event $event, string $field): mixed
    {
        $metadata = $event->getMetadata();

        return match ($field) {
            'uuid', 'message_id', 'messageId' => $metadata[MessageHeaders::MESSAGE_ID] ?? null,
            'event_name', 'message_name', 'messageName' => $event->getEventName(),
            'created_at', 'createdAt', 'timestamp' => $metadata[MessageHeaders::TIMESTAMP] ?? null,
            default => throw new InvalidArgumentException("Unexpected field '{$field}' given"),
        };
    }

    private function match(Operator $operator, mixed $value, mixed $expected): bool
    {
        return match ($operator) {
            Operator::EQUALS => $value === $expected,
            Operator::GREATER_THAN => $value > $expected,
            Operator::GREATER_THAN_EQUALS => $value >= $expected,
            Operator::IN => in_array($value, $expected, true),
            Operator::LOWER_THAN => $value < $expected,
            Operator::LOWER_THAN_EQUALS => $value <= $expected,
            Operator::NOT_EQUALS => $value !== $expected,
            Operator::NOT_IN => ! in_array($value, $expected, true),
            Operator::REGEX => (bool) preg_match('/' . $expected . '/', (string) $value),
        };
    }
}
