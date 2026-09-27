<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\Tagging\EventTagRegistry;
use Ecotone\Messaging\Message;
use Ecotone\Modelling\Event;

use function is_object;

/**
 * Shared by every DecisionModelConverter of one handler invocation: whichever of its injected
 * model parameters gets resolved first performs the batched load for all of them -- one
 * loadByCriteria() combining every model's EventCriteria with or() -- and the rest simply read
 * the already-folded instances back out of the collector.
 *
 * licence Enterprise
 */
final class DecisionModelBatchLoader
{
    /**
     * @param DecisionModelParameterLoader[] $loaders
     */
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly EventTagRegistry $eventTagRegistry,
        private readonly DecisionModelAppendConditionCollector $conditionCollector,
        private readonly DecisionModelLoadedInstancesCollector $instancesCollector,
        private readonly array $loaders,
    ) {
    }

    public function ensureLoaded(Message $message): void
    {
        $messageId = $message->getHeaders()->getMessageId();
        if ($this->instancesCollector->hasBatchFor($messageId)) {
            return;
        }

        $criteriaByParameterName = [];
        foreach ($this->loaders as $loader) {
            $criteriaByParameterName[$loader->parameterName()] = $loader->resolveCriteria($message);
        }

        $combinedCriteria = null;
        foreach ($criteriaByParameterName as $criteria) {
            if ($criteria === null) {
                continue;
            }

            $combinedCriteria = $combinedCriteria === null ? $criteria : $combinedCriteria->or($criteria);
        }

        $loadedEvents = $combinedCriteria === null
            ? new LoadedEvents([], AppendCondition::empty())
            : $this->eventStore->loadByCriteria($combinedCriteria);

        $instancesByParameterName = [];
        foreach ($this->loaders as $loader) {
            $criteria = $criteriaByParameterName[$loader->parameterName()];

            $instancesByParameterName[$loader->parameterName()] = $criteria === null
                ? null
                : $loader->fold(self::eventsMatching($loadedEvents->events, $criteria, $this->eventTagRegistry));
        }

        $this->instancesCollector->record($messageId, $instancesByParameterName);

        if (! $loadedEvents->appendCondition->isEmpty()) {
            $this->conditionCollector->record($messageId, $loadedEvents->appendCondition);
        }
    }

    /**
     * @param Event[] $events
     * @return Event[]
     */
    private static function eventsMatching(array $events, EventCriteria $criteria, EventTagRegistry $eventTagRegistry): array
    {
        $requiredTags = $criteria->tags();

        $matching = [];
        foreach ($events as $event) {
            if (! $criteria->matchesEventType($event->getEventName())) {
                continue;
            }

            if ($requiredTags !== [] && ! self::eventCarriesAllTags($event, $requiredTags, $eventTagRegistry)) {
                continue;
            }

            $matching[] = $event;
        }

        return $matching;
    }

    /**
     * @param array<array{name: string, value: string}> $requiredTags
     */
    private static function eventCarriesAllTags(Event $event, array $requiredTags, EventTagRegistry $eventTagRegistry): bool
    {
        $payload = $event->getPayload();
        $eventTags = is_object($payload) ? $eventTagRegistry->tagsFor($payload) : [];

        foreach ($requiredTags as $requiredTag) {
            $found = false;
            foreach ($eventTags as $eventTag) {
                if ($eventTag['name'] === $requiredTag['name'] && $eventTag['value'] === $requiredTag['value']) {
                    $found = true;

                    break;
                }
            }

            if (! $found) {
                return false;
            }
        }

        return true;
    }
}
