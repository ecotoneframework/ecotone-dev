<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Handler\ClosureExpression\AttributeExpressionExecutor;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Message;
use Ecotone\Modelling\EventSourcingExecutor\EventSourcingHandlerExecutor;

use function is_array;
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
        private readonly EventStore $eventStore,
        private readonly EventSourcingHandlerExecutor $eventSourcingHandlerExecutor,
        private readonly DecisionModelAppendConditionCollector $collector,
        private readonly ParameterConverter $payloadConverter,
        private readonly ?AttributeExpressionExecutor $expressionExecutor = null,
    ) {
    }

    public function getArgumentFrom(Message $message): ?object
    {
        $definition = $this->decisionModelDefinitionRegistry->get($this->modelClassName);
        $payload = $this->payloadConverter->getArgumentFrom($message);

        $tagValues = $this->expressionExecutor !== null
            ? $this->resolveTagValuesFromExpression($definition, $message)
            : $this->resolveTagValuesFromPayload($definition, $payload);

        if ($tagValues === null) {
            return null;
        }

        $criteria = $definition->toCriteria($tagValues);
        $loadedEvents = $this->eventStore->loadByCriteria($criteria);

        $this->collector->record($message->getHeaders()->getMessageId(), $loadedEvents->appendCondition);

        return $this->eventSourcingHandlerExecutor->fill($loadedEvents->events, null);
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveTagValuesFromPayload(DecisionModelDefinition $definition, mixed $payload): ?array
    {
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

        return $tagValues;
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveTagValuesFromExpression(DecisionModelDefinition $definition, Message $message): ?array
    {
        $resolved = $this->expressionExecutor->execute($message);
        $tagNames = $definition->tagNames();

        $tagValues = [];
        foreach ($tagNames as $tagName) {
            $value = is_array($resolved)
                ? ($resolved[$tagName] ?? null)
                : (count($tagNames) === 1 ? $resolved : null);

            if ($value === null) {
                if ($this->doesAllowNulls) {
                    return null;
                }

                throw ConfigurationException::create(sprintf(
                    "#[Fetch] expression for DecisionModel %s did not resolve tag '%s'.",
                    $this->modelClassName,
                    $tagName
                ));
            }

            $tagValues[$tagName] = (string) $value;
        }

        return $tagValues;
    }
}
