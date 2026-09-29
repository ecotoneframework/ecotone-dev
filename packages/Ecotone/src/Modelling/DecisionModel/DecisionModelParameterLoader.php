<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use function array_is_list;

use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\EventSourcing\Tagging\EventTagValueNormalizer;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Handler\ClosureExpression\AttributeExpressionExecutor;
use Ecotone\Messaging\Handler\ExpressionEvaluationException;
use Ecotone\Messaging\Handler\ExpressionResult;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Message;
use Ecotone\Modelling\Event;
use Ecotone\Modelling\EventSourcingExecutor\EventSourcingHandlerExecutor;

use function implode;
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
            $value = $this->normalizedTagValueFromExpression($tagName, $resolved, is_array($resolved) && ! array_is_list($resolved)
                ? ($resolved[$tagName] ?? null)
                : (count($tagNames) === 1 ? $resolved : null));

            if ($value === null) {
                if ($this->doesAllowNulls) {
                    return null;
                }

                throw ExpressionEvaluationException::because($this->expressionExecutor->location(), sprintf(
                    "DecisionModel %s did not resolve tag '%s'. The expression returned %s. %s",
                    $this->modelClassName,
                    $tagName,
                    ExpressionResult::describe($resolved),
                    $this->shapeGuidanceFor($tagNames),
                ));
            }

            $tagValues[$tagName] = $value;
        }

        return $tagValues;
    }

    /**
     * @param string[] $tagNames
     */
    private function shapeGuidanceFor(array $tagNames): string
    {
        return count($tagNames) === 1
            ? 'A single-tag model needs a scalar or Stringable value; declare the parameter nullable to let it contribute nothing.'
            : sprintf("This model is scoped by '%s'; the expression must return an array keyed by those names.", implode("' and '", $tagNames));
    }

    private function normalizedTagValueFromExpression(string $tagName, mixed $resolved, mixed $value): ?string
    {
        if (is_array($value)) {
            throw ExpressionEvaluationException::because($this->expressionExecutor->location(), sprintf(
                "DecisionModel %s cannot use the value resolved for tag '%s': the expression supplies several values, but a model is scoped by one value per tag. Inject the model once per value with #[Fetch], or express a boundary over several values with #[DecisionBoundary].",
                $this->modelClassName,
                $tagName,
            ));
        }

        try {
            return EventTagValueNormalizer::normalize($tagName, $value)[0] ?? null;
        } catch (ConfigurationException $exception) {
            throw ExpressionEvaluationException::because($this->expressionExecutor->location(), sprintf(
                "DecisionModel %s cannot use the value resolved for tag '%s': %s",
                $this->modelClassName,
                $tagName,
                $exception->getMessage(),
            ));
        }
    }

    private function normalizedTagValue(string $tagName, mixed $value): ?string
    {
        if (is_array($value)) {
            throw ConfigurationException::create(sprintf(
                "Could not resolve tag '%s' for DecisionModel %s: the message supplies several values, but a model is scoped by one value per tag. Inject the model once per value with #[Fetch], or express a boundary over several values with #[DecisionBoundary].",
                $tagName,
                $this->modelClassName,
            ));
        }

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
