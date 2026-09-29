<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use function array_filter;
use function array_values;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\EventSourcing\EventStore;
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
        $aggregateInstancesByParameterName = $this->aggregateInstancesByParameterName($message);

        $loadedEvents = $this->loadEventsFor([
            ...$criteriaByParameterName,
            ...$this->fetchedAggregateCriteriaByParameterName($message),
            ...$this->decisionBoundaryCriteria($message),
            ...$this->captureCriteriaOf($aggregateInstancesByParameterName),
        ]);

        $pendingSnapshots = [];
        $instancesByParameterName = [
            ...$this->foldInstances($criteriaByParameterName, $loadedEvents->events),
            ...$this->foldAggregateBackedInstances($aggregateInstancesByParameterName, $pendingSnapshots),
        ];

        return [DecisionModelLoadedState::HEADER_NAME => new DecisionModelLoadedState($instancesByParameterName, $loadedEvents->appendCondition, $pendingSnapshots)];
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
     * @return EventCriteria[]
     */
    private function decisionBoundaryCriteria(Message $message): array
    {
        return array_map(static fn (DecisionBoundaryEvaluator $decisionBoundary): EventCriteria => $decisionBoundary->criteriaFor($message), $this->decisionBoundaries);
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
     * @param Event[] $events
     * @return array<string, ?object>
     */
    private function foldInstances(array $criteriaByParameterName, array $events): array
    {
        $instancesByParameterName = [];
        foreach ($this->loaders as $loader) {
            $criteria = $criteriaByParameterName[$loader->parameterName()];

            $instancesByParameterName[$loader->parameterName()] = $criteria === null
                ? null
                : $loader->fold($this->tagResolver->eventsMatching($events, $criteria));
        }

        return $instancesByParameterName;
    }
}
