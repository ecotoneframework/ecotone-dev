<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use function array_filter;
use function array_values;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\Tagging\MatchedTagSequences;
use Ecotone\EventSourcing\Tagging\TagKey;
use Ecotone\EventSourcing\Tagging\TagResolver;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\DecisionModel\Snapshot\DecisionModelSnapshot;
use Ecotone\Modelling\DecisionModel\Snapshot\DecisionModelSnapshotStore;
use Ecotone\Modelling\DecisionModel\Snapshot\PendingDecisionModelSnapshot;
use Ecotone\Modelling\Event;

use function in_array;
use function is_object;
use function max;
use function sprintf;

/**
 * licence Enterprise
 */
final class DecisionModelBatchLoader
{
    /**
     * @param DecisionModelParameterLoader[] $loaders
     * @param AggregateBackedDecisionModelLoader[] $aggregateBackedLoaders
     * @param FetchedAggregateCounterCapture[] $fetchedAggregateCaptures
     * @param DecisionBoundaryEvaluator[] $decisionBoundaries
     */
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly TagResolver $tagResolver,
        private readonly array $loaders,
        private readonly array $aggregateBackedLoaders,
        private readonly array $fetchedAggregateCaptures,
        private readonly array $decisionBoundaries,
        private readonly DecisionModelSnapshotStore $snapshots,
    ) {
    }

    /**
     * @return array<string, DecisionModelLoadedState>
     */
    public function load(Message $message): array
    {
        $criteriaByParameterName = $this->criteriaByParameterName($message);
        $tagSnapshotsByParameterName = $this->snapshotsOfEachTagScope($criteriaByParameterName);
        $criteriaByParameterName = self::boundedBySnapshots($criteriaByParameterName, $tagSnapshotsByParameterName);
        $aggregateInstancesByParameterName = $this->aggregateInstancesByParameterName($message);
        $boundaryCriteriaByLabel = $this->decisionBoundaryCriteriaByLabel($message);

        $loadedEvents = $this->loadEventsFor([
            ...$criteriaByParameterName,
            ...$this->fetchedAggregateCriteriaByParameterName($message),
            ...$boundaryCriteriaByLabel,
            ...$this->captureCriteriaOf($aggregateInstancesByParameterName),
        ]);

        $pendingSnapshots = [];
        $instancesByParameterName = [
            ...$this->foldInstances($criteriaByParameterName, $tagSnapshotsByParameterName, $loadedEvents, $pendingSnapshots),
            ...$this->foldAggregateBackedInstances($aggregateInstancesByParameterName, $pendingSnapshots),
        ];

        $appendCondition = $loadedEvents->appendCondition->withDecidingScopes(
            $this->decidingScopeNamesByTagKey($criteriaByParameterName, $boundaryCriteriaByLabel),
        );

        return [DecisionModelLoadedState::HEADER_NAME => new DecisionModelLoadedState($instancesByParameterName, $appendCondition, $pendingSnapshots)];
    }

    /**
     * @param array<string, ?EventCriteria> $criteriaByParameterName
     * @param array<string, EventCriteria> $boundaryCriteriaByLabel
     * @return array<string, string[]>
     */
    private function decidingScopeNamesByTagKey(array $criteriaByParameterName, array $boundaryCriteriaByLabel): array
    {
        $decidingScopeNamesByTagKey = [];
        foreach ($this->loaders as $loader) {
            $criteria = $criteriaByParameterName[$loader->parameterName()];
            if ($criteria !== null) {
                $decidingScopeNamesByTagKey = self::nameScopeOnEveryTagOf($criteria, $loader->modelClassName(), $decidingScopeNamesByTagKey);
            }
        }

        foreach ($boundaryCriteriaByLabel as $label => $criteria) {
            $decidingScopeNamesByTagKey = self::nameScopeOnEveryTagOf($criteria, $label, $decidingScopeNamesByTagKey);
        }

        return $decidingScopeNamesByTagKey;
    }

    /**
     * @param array<string, string[]> $decidingScopeNamesByTagKey
     * @return array<string, string[]>
     */
    private static function nameScopeOnEveryTagOf(EventCriteria $criteria, string $scopeName, array $decidingScopeNamesByTagKey): array
    {
        foreach ($criteria->branches() as $branch) {
            foreach ($branch->tags() as $tag) {
                $tagKey = TagKey::of($tag['name'], $tag['value']);

                if (! in_array($scopeName, $decidingScopeNamesByTagKey[$tagKey] ?? [], true)) {
                    $decidingScopeNamesByTagKey[$tagKey][] = $scopeName;
                }
            }
        }

        return $decidingScopeNamesByTagKey;
    }

    /**
     * @return array<string, ?EventCriteria>
     */
    private function criteriaByParameterName(Message $message): array
    {
        $criteriaByParameterName = [];
        foreach ($this->loaders as $loader) {
            $criteriaByParameterName[$loader->parameterName()] = $loader->resolveCriteria($message);
        }

        return $criteriaByParameterName;
    }

    /**
     * @return array<string, ?EventCriteria>
     */
    private function fetchedAggregateCriteriaByParameterName(Message $message): array
    {
        $criteriaByParameterName = [];
        foreach ($this->fetchedAggregateCaptures as $fetchedAggregateCapture) {
            $criteriaByParameterName[$fetchedAggregateCapture->parameterName()] = $fetchedAggregateCapture->resolveCriteria($message);
        }

        return $criteriaByParameterName;
    }

    /**
     * @return array<string, EventCriteria>
     */
    private function decisionBoundaryCriteriaByLabel(Message $message): array
    {
        $criteriaByLabel = [];
        foreach ($this->decisionBoundaries as $decisionBoundary) {
            $criteriaByLabel[$decisionBoundary->decidedByLabel()] = $decisionBoundary->criteriaFor($message);
        }

        return $criteriaByLabel;
    }

    /**
     * @param array<string|int, ?EventCriteria> $criteriaByParameterName
     */
    private function loadEventsFor(array $criteriaByParameterName): LoadedEvents
    {
        $combinedCriteria = null;
        foreach ($criteriaByParameterName as $criteria) {
            if ($criteria !== null) {
                $combinedCriteria = $combinedCriteria?->or($criteria) ?? $criteria;
            }
        }

        return $combinedCriteria === null
            ? new LoadedEvents([], AppendCondition::empty())
            : $this->eventStore->loadByCriteria($combinedCriteria);
    }

    /**
     * @return array<string, ?AggregateBackedDecisionModelInstance>
     */
    private function aggregateInstancesByParameterName(Message $message): array
    {
        $instancesByParameterName = [];
        foreach ($this->aggregateBackedLoaders as $loader) {
            $instancesByParameterName[$loader->parameterName()] = $loader->resolveInstance($message);
        }

        return $instancesByParameterName;
    }

    /**
     * @param array<string, ?AggregateBackedDecisionModelInstance> $instancesByParameterName
     * @return array<string, EventCriteria>
     */
    private function captureCriteriaOf(array $instancesByParameterName): array
    {
        $criteriaByParameterName = [];
        foreach ($instancesByParameterName as $parameterName => $instance) {
            if ($instance !== null) {
                $criteriaByParameterName[$parameterName] = $instance->captureCriteria();
            }
        }

        return $criteriaByParameterName;
    }

    /**
     * @param array<string, ?AggregateBackedDecisionModelInstance> $instancesByParameterName
     * @param PendingDecisionModelSnapshot[] $pendingSnapshots
     * @return array<string, ?object>
     */
    private function foldAggregateBackedInstances(array $instancesByParameterName, array &$pendingSnapshots): array
    {
        $snapshotsByParameterName = $this->snapshotsOfEachAggregateInstance($instancesByParameterName);
        $eventsByReadKey = $this->readEventsOfEachAggregateInstance($instancesByParameterName, $snapshotsByParameterName);

        $foldedByParameterName = [];
        foreach ($this->aggregateBackedLoaders as $loader) {
            $parameterName = $loader->parameterName();
            $instance = $instancesByParameterName[$parameterName];

            if ($instance === null) {
                $foldedByParameterName[$parameterName] = null;

                continue;
            }

            $snapshot = $snapshotsByParameterName[$parameterName];
            $readEvents = $eventsByReadKey[self::readKeyOf($instance, $snapshot)];

            if ($snapshot !== null && ! self::holdsTheEventAt($readEvents, $snapshot->coveredPosition)) {
                $this->snapshots->logIgnored($instance->modelClassName(), $instance->snapshotScopeKey(), sprintf(
                    'it covers version %d, which %s has not reached',
                    $snapshot->coveredPosition,
                    $instance->describeInstance(),
                ));
                $snapshot = null;
                $readEvents = $this->wholeHistoryOf($instance);
            }

            $tail = $snapshot === null ? $readEvents : self::eventsAfter($readEvents, $snapshot->coveredPosition);
            $folded = $loader->fold($tail, $snapshot?->state);

            $foldedByParameterName[$parameterName] = $folded;

            $pendingSnapshot = $this->snapshots->pendingWriteFor(
                $instance->modelClassName(),
                $instance->snapshotScopeKey(),
                $instance->foldShape(),
                $folded,
                self::highestFoldedVersionIn($tail, $instance->handledEventClasses(), $snapshot?->coveredPosition ?? 0),
                $snapshot?->coveredPosition ?? 0,
            );

            if ($pendingSnapshot !== null) {
                $pendingSnapshots[] = $pendingSnapshot;
            }
        }

        return $foldedByParameterName;
    }

    /**
     * @param array<string, ?AggregateBackedDecisionModelInstance> $instancesByParameterName
     * @return array<string, ?DecisionModelSnapshot>
     */
    private function snapshotsOfEachAggregateInstance(array $instancesByParameterName): array
    {
        $snapshotsByParameterName = [];
        foreach ($this->aggregateBackedLoaders as $loader) {
            $instance = $instancesByParameterName[$loader->parameterName()];

            $snapshotsByParameterName[$loader->parameterName()] = $instance === null
                ? null
                : $this->snapshots->load($instance->modelClassName(), $instance->snapshotScopeKey(), $instance->foldShape());
        }

        return $snapshotsByParameterName;
    }

    /**
     * The read starts at the snapshot's own position rather than one past it, so the event the
     * snapshot last folded comes back with the tail and proves the snapshot is not ahead of the
     * aggregate.
     *
     * @param array<string, ?AggregateBackedDecisionModelInstance> $instancesByParameterName
     * @param array<string, ?DecisionModelSnapshot> $snapshotsByParameterName
     * @return array<string, Event[]>
     */
    private function readEventsOfEachAggregateInstance(array $instancesByParameterName, array $snapshotsByParameterName): array
    {
        $eventClassesByReadKey = [];
        $instanceByReadKey = [];
        $fromVersionByReadKey = [];
        foreach ($instancesByParameterName as $parameterName => $instance) {
            if ($instance === null) {
                continue;
            }

            $snapshot = $snapshotsByParameterName[$parameterName] ?? null;
            $readKey = self::readKeyOf($instance, $snapshot);
            $instanceByReadKey[$readKey] = $instance;
            $fromVersionByReadKey[$readKey] = max(1, $snapshot?->coveredPosition ?? 1);
            $eventClassesByReadKey[$readKey] = array_values(array_unique([
                ...$eventClassesByReadKey[$readKey] ?? [],
                ...$instance->handledEventClasses(),
            ]));
        }

        $eventsByReadKey = [];
        foreach ($instanceByReadKey as $readKey => $instance) {
            $eventsByReadKey[$readKey] = $this->readAggregateEvents($instance, $fromVersionByReadKey[$readKey], $eventClassesByReadKey[$readKey]);
        }

        return $eventsByReadKey;
    }

    /**
     * @return Event[]
     */
    private function wholeHistoryOf(AggregateBackedDecisionModelInstance $instance): array
    {
        return $this->readAggregateEvents($instance, 1, $instance->handledEventClasses());
    }

    /**
     * @param class-string[] $eventClasses
     * @return Event[]
     */
    private function readAggregateEvents(AggregateBackedDecisionModelInstance $instance, int $fromVersion, array $eventClasses): array
    {
        return [...$this->eventStore->loadAggregateEvents(
            $instance->streamName(),
            $instance->aggregateType(),
            $instance->aggregateId(),
            $fromVersion,
            null,
            $eventClasses,
        )];
    }

    private static function readKeyOf(AggregateBackedDecisionModelInstance $instance, ?DecisionModelSnapshot $snapshot): string
    {
        return sprintf('%s|%d', $instance->instanceKey(), $snapshot?->coveredPosition ?? 0);
    }

    /**
     * @param Event[] $events
     */
    private static function holdsTheEventAt(array $events, int $version): bool
    {
        foreach ($events as $event) {
            if (self::versionOf($event) === $version) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Event[] $events
     * @return Event[]
     */
    private static function eventsAfter(array $events, int $version): array
    {
        return array_values(array_filter($events, static fn (Event $event): bool => self::versionOf($event) > $version));
    }

    /**
     * @param Event[] $events
     * @param class-string[] $handledEventClasses
     */
    private static function highestFoldedVersionIn(array $events, array $handledEventClasses, int $coveredPosition): int
    {
        foreach ($events as $event) {
            $payload = $event->getPayload();
            $eventClass = is_object($payload) ? $payload::class : $event->getEventName();

            if (in_array($eventClass, $handledEventClasses, true)) {
                $coveredPosition = max($coveredPosition, self::versionOf($event));
            }
        }

        return $coveredPosition;
    }

    private static function versionOf(Event $event): int
    {
        return (int) ($event->getMetadata()[MessageHeaders::EVENT_AGGREGATE_VERSION] ?? 0);
    }

    /**
     * @param array<string, ?EventCriteria> $criteriaByParameterName
     * @param array<string, ?DecisionModelSnapshot> $snapshotsByParameterName
     * @param PendingDecisionModelSnapshot[] $pendingSnapshots
     * @return array<string, ?object>
     */
    private function foldInstances(array $criteriaByParameterName, array $snapshotsByParameterName, LoadedEvents $loadedEvents, array &$pendingSnapshots): array
    {
        $instancesByParameterName = [];
        foreach ($this->loaders as $loader) {
            $parameterName = $loader->parameterName();
            $criteria = $criteriaByParameterName[$parameterName];

            if ($criteria === null) {
                $instancesByParameterName[$parameterName] = null;

                continue;
            }

            $snapshot = $snapshotsByParameterName[$parameterName];
            if ($snapshot !== null && $this->runsAheadOfTheCapture($loader, $criteria, $snapshot, $loadedEvents->appendCondition)) {
                $criteria = $criteria->afterTagSequence(0);
                $snapshot = null;
            }

            $tail = $this->tagResolver->eventsMatching(
                $snapshot === null && $criteria !== $criteriaByParameterName[$parameterName]
                    ? $this->eventStore->loadByCriteria($criteria)->events
                    : $loadedEvents->events,
                $criteria,
            );
            $folded = $loader->fold($tail, $snapshot?->state);

            $instancesByParameterName[$parameterName] = $folded;

            $pendingSnapshot = $this->snapshots->pendingWriteFor(
                $loader->modelClassName(),
                DecisionModelParameterLoader::snapshotScopeKeyOf($criteria),
                $loader->foldShape(),
                $folded,
                $this->highestPositionSequenceIn($tail, $criteria, $snapshot?->coveredPosition ?? 0),
                $snapshot?->coveredPosition ?? 0,
            );

            if ($pendingSnapshot !== null) {
                $pendingSnapshots[] = $pendingSnapshot;
            }
        }

        return $instancesByParameterName;
    }

    /**
     * @param array<string, ?EventCriteria> $criteriaByParameterName
     * @return array<string, ?DecisionModelSnapshot>
     */
    private function snapshotsOfEachTagScope(array $criteriaByParameterName): array
    {
        $snapshotsByParameterName = [];
        foreach ($this->loaders as $loader) {
            $criteria = $criteriaByParameterName[$loader->parameterName()];

            $snapshotsByParameterName[$loader->parameterName()] = $criteria === null
                ? null
                : $this->snapshots->load($loader->modelClassName(), DecisionModelParameterLoader::snapshotScopeKeyOf($criteria), $loader->foldShape());
        }

        return $snapshotsByParameterName;
    }

    /**
     * @param array<string, ?EventCriteria> $criteriaByParameterName
     * @param array<string, ?DecisionModelSnapshot> $snapshotsByParameterName
     * @return array<string, ?EventCriteria>
     */
    private static function boundedBySnapshots(array $criteriaByParameterName, array $snapshotsByParameterName): array
    {
        foreach ($snapshotsByParameterName as $parameterName => $snapshot) {
            if ($snapshot !== null) {
                $criteriaByParameterName[$parameterName] = $criteriaByParameterName[$parameterName]->afterTagSequence($snapshot->coveredPosition);
            }
        }

        return $criteriaByParameterName;
    }

    /**
     * A snapshot written on a connection the event store does not share can commit a position the
     * reader cannot see yet. The captured counter of the position tag is the exact bound, and it
     * comes back from the same read.
     */
    private function runsAheadOfTheCapture(
        DecisionModelParameterLoader $loader,
        EventCriteria $criteria,
        DecisionModelSnapshot $snapshot,
        AppendCondition $appendCondition,
    ): bool {
        $positionTag = $this->tagResolver->positionTagOf($criteria);
        foreach ($appendCondition->expectedTagVersions() as $expected) {
            if ($expected['name'] !== $positionTag['name'] || $expected['value'] !== $positionTag['value'] || $snapshot->coveredPosition <= $expected['expectedVersion']) {
                continue;
            }

            $this->snapshots->logIgnored($loader->modelClassName(), DecisionModelParameterLoader::snapshotScopeKeyOf($criteria), sprintf(
                'it covers sequence %d, which is beyond the %d captured for the scope',
                $snapshot->coveredPosition,
                $expected['expectedVersion'],
            ));

            return true;
        }

        return false;
    }

    /**
     * @param Event[] $events
     */
    private function highestPositionSequenceIn(array $events, EventCriteria $criteria, int $coveredPosition): int
    {
        $positionTag = $this->tagResolver->positionTagOf($criteria);
        if ($positionTag === null) {
            return $coveredPosition;
        }

        $positionTagKey = TagKey::of($positionTag['name'], $positionTag['value']);
        foreach ($events as $event) {
            $coveredPosition = max($coveredPosition, MatchedTagSequences::of($event, $positionTagKey) ?? 0);
        }

        return $coveredPosition;
    }
}
