<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\Tagging\TagResolver;
use Ecotone\Messaging\Message;
use Ecotone\Modelling\Event;

/**
 * licence Enterprise
 */
final class DecisionModelBatchLoader
{
    /**
     * @param DecisionModelParameterLoader[] $loaders
     * @param FetchedAggregateCounterCapture[] $fetchedAggregateCaptures
     * @param DecisionBoundaryEvaluator[] $decisionBoundaries
     */
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly TagResolver $tagResolver,
        private readonly array $loaders,
        private readonly array $fetchedAggregateCaptures,
        private readonly array $decisionBoundaries,
    ) {
    }

    /**
     * @return array<string, DecisionModelLoadedState>
     */
    public function load(Message $message): array
    {
        $criteriaByParameterName = $this->criteriaByParameterName($message);
        $loadedEvents = $this->loadEventsFor([
            ...$criteriaByParameterName,
            ...$this->fetchedAggregateCriteriaByParameterName($message),
            ...$this->decisionBoundaryCriteria($message),
        ]);
        $instancesByParameterName = $this->foldInstances($criteriaByParameterName, $loadedEvents->events);

        return [DecisionModelLoadedState::HEADER_NAME => new DecisionModelLoadedState($instancesByParameterName, $loadedEvents->appendCondition)];
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
