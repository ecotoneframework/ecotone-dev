<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\EventSourcing\Tagging\EventTagValueNormalizer;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Handler\ClosureExpression\AttributeExpressionExecutor;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Message;
use Ecotone\Modelling\Event;
use Ecotone\Modelling\EventSourcingExecutor\EventSourcingHandlerExecutor;

use function is_array;
use function sprintf;

/**
 * licence Enterprise
 */
final class DecisionModelParameterLoader
{
    public function __construct(
        private readonly string $parameterName,
        private readonly string $modelClassName,
        private readonly bool $doesAllowNulls,
        private readonly DecisionModelDefinitionRegistry $decisionModelDefinitionRegistry,
        private readonly EventSourcingHandlerExecutor $eventSourcingHandlerExecutor,
        private readonly ParameterConverter $payloadConverter,
        private readonly AttributeExpressionExecutor $expressionExecutor,
    ) {
    }

    public function parameterName(): string
    {
        return $this->parameterName;
    }

    public function resolveCriteria(Message $message): ?EventCriteria
    {
        $definition = $this->decisionModelDefinitionRegistry->get($this->modelClassName);
        $payload = $this->payloadConverter->getArgumentFrom($message);

        $tagValues = $this->expressionExecutor->hasExpression()
            ? $this->resolveTagValuesFromExpression($definition, $message)
            : $this->resolveTagValuesFromPayload($definition, $payload);

        return $tagValues === null ? null : $definition->toCriteria($tagValues);
    }

    /**
     * @param Event[] $events
     */
    public function fold(array $events): object
    {
        return $this->eventSourcingHandlerExecutor->fill($events, null);
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveTagValuesFromPayload(DecisionModelDefinition $definition, mixed $payload): ?array
    {
        $tagValues = [];
        foreach ($definition->tagNames() as $tagName) {
            $value = is_object($payload) ? $this->normalizedTagValue($tagName, MessageTagValueResolver::resolve($tagName, $payload)) : null;

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
            $value = $this->normalizedTagValue($tagName, is_array($resolved)
                ? ($resolved[$tagName] ?? null)
                : (count($tagNames) === 1 ? $resolved : null));

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

            $tagValues[$tagName] = $value;
        }

        return $tagValues;
    }

    private function normalizedTagValue(string $tagName, mixed $value): ?string
    {
        try {
            return EventTagValueNormalizer::normalize($tagName, $value)[0] ?? null;
        } catch (ConfigurationException $exception) {
            throw ConfigurationException::create(sprintf(
                'Could not resolve tag \'%s\' for DecisionModel %s: %s',
                $tagName,
                $this->modelClassName,
                $exception->getMessage(),
            ));
        }
    }
}
