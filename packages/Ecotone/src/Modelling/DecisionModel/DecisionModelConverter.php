<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\EventSourcing\TaggedEventStore;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Message;
use Ecotone\Modelling\EventSourcingExecutor\EventSourcingHandlerExecutor;

use function sprintf;

/**
 * licence Enterprise
 */
final class DecisionModelConverter implements ParameterConverter
{
    public function __construct(
        private readonly string $modelClassName,
        private readonly bool $doesAllowNulls,
        private readonly DecisionModelDefinitionRegistry $decisionModelDefinitionRegistry,
        private readonly TaggedEventStore $taggedEventStore,
        private readonly EventSourcingHandlerExecutor $eventSourcingHandlerExecutor,
        private readonly DecisionModelAppendConditionCollector $collector,
    ) {
    }

    public function getArgumentFrom(Message $message): ?object
    {
        $definition = $this->decisionModelDefinitionRegistry->get($this->modelClassName);
        $payload = $message->getPayload();

        $tagValues = [];
        foreach ($definition->tagNames() as $tagName) {
            $value = is_object($payload) ? MessageTagValueResolver::resolve($tagName, $payload) : null;

            if ($value === null) {
                if ($this->doesAllowNulls) {
                    return null;
                }

                throw ConfigurationException::create(sprintf(
                    "Could not resolve tag '%s' for DecisionModel %s from message %s. Add #[EventTag('%s')] to a property, name a property '%sId', or use #[Fetch] to map it explicitly.",
                    $tagName,
                    $this->modelClassName,
                    is_object($payload) ? $payload::class : gettype($payload),
                    $tagName,
                    $tagName
                ));
            }

            $tagValues[$tagName] = $value;
        }

        $criteria = $definition->toCriteria($tagValues);
        $loadedEvents = $this->taggedEventStore->load($criteria);

        $this->collector->record($message->getHeaders()->getMessageId(), $loadedEvents->appendCondition);

        return $this->eventSourcingHandlerExecutor->fill($loadedEvents->events, null);
    }
}
