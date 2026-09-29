<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Handler\ClosureExpression\AttributeExpressionExecutor;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\FetchAggregateConverter;
use Ecotone\Messaging\Message;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\AggregateDefinitionRegistry;
use Ecotone\Modelling\Event;
use Ecotone\Modelling\EventSourcingExecutor\EventSourcingHandlerExecutor;

use function implode;
use function is_object;
use function sprintf;

/**
 * licence Enterprise
 */
final class AggregateBackedDecisionModelLoader
{
    public function __construct(
        private readonly string $parameterName,
        private readonly string $modelClassName,
        private readonly bool $doesAllowNulls,
        private readonly DecisionModelDefinitionRegistry $decisionModelDefinitionRegistry,
        private readonly EventSourcingHandlerExecutor $eventSourcingHandlerExecutor,
        private readonly ParameterConverter $payloadConverter,
        private readonly AttributeExpressionExecutor $expressionExecutor,
        private readonly AggregateDefinitionRegistry $aggregateDefinitionRegistry,
    ) {
    }

    public function parameterName(): string
    {
        return $this->parameterName;
    }

    public function resolveInstance(Message $message): ?AggregateBackedDecisionModelInstance
    {
        $definition = $this->decisionModelDefinitionRegistry->getAggregateBacked($this->modelClassName);

        $identifiers = $this->expressionExecutor->hasExpression()
            ? $this->identifiersFromExpression($definition, $message)
            : $this->identifiersFromPayload($definition, $this->payloadConverter->getArgumentFrom($message));

        return $identifiers === null ? null : $definition->instanceFor($identifiers);
    }

    /**
     * @param Event[] $events
     */
    public function fold(array $events): object
    {
        return $this->eventSourcingHandlerExecutor->fill($events, null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function identifiersFromExpression(AggregateBackedDecisionModelDefinition $definition, Message $message): ?array
    {
        $identifiers = FetchAggregateConverter::identifiersFrom(
            $this->expressionExecutor->execute($message, ['value' => $message->getPayload()]),
            $definition->aggregateClassName(),
            $this->aggregateDefinitionRegistry,
        );

        if ($identifiers !== null) {
            return $identifiers;
        }

        if ($this->doesAllowNulls) {
            return null;
        }

        throw ConfigurationException::create(sprintf(
            '#[Fetch] expression for DecisionModel %s did not resolve an identifier of aggregate %s.',
            $this->modelClassName,
            $definition->aggregateClassName(),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function identifiersFromPayload(AggregateBackedDecisionModelDefinition $definition, mixed $payload): ?array
    {
        $identifiers = [];
        foreach ($definition->identifierNames() as $identifierName) {
            $value = is_object($payload) ? MessageAggregateIdentifierResolver::resolve($identifierName, $payload) : null;

            if ($value === null) {
                if ($this->doesAllowNulls) {
                    return null;
                }

                throw ConfigurationException::create(sprintf(
                    "Could not resolve identifier '%s' of aggregate %s for DecisionModel %s from message %s. Name a property '%s', or map it explicitly with #[Fetch].",
                    $identifierName,
                    $definition->aggregateClassName(),
                    $this->modelClassName,
                    is_object($payload) ? $payload::class : gettype($payload),
                    implode("', '", MessageAggregateIdentifierResolver::candidatePropertyNamesFor($identifierName)),
                ));
            }

            $identifiers[$identifierName] = $value;
        }

        return $identifiers;
    }
}
