<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter;

use Ecotone\Messaging\Config\LicenceDecider;
use Ecotone\Messaging\Handler\ClosureExpression\AttributeExpressionExecutor;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\AggregateDefinitionRegistry;
use Ecotone\Modelling\AggregateNotFoundException;
use Ecotone\Modelling\Repository\AllAggregateRepository;
use InvalidArgumentException;

/**
 * licence Enterprise
 */
class FetchAggregateConverter implements ParameterConverter
{
    public function __construct(
        private AllAggregateRepository $aggregateRepository,
        private string $aggregateClassName,
        private AttributeExpressionExecutor $expressionExecutor,
        private bool $doesAllowsNull,
        private LicenceDecider $licenceDecider,
        private AggregateDefinitionRegistry $aggregateDefinitionRegistry,
    ) {
    }

    public function getArgumentFrom(Message $message): ?object
    {
        if (! $this->licenceDecider->hasEnterpriseLicence()) {
            throw LicensingException::create('FetchAggregate attribute is available as part of Ecotone Enterprise.');
        }

        $identifiers = self::identifiersFrom($this->resolveIdentifiers($message), $this->aggregateClassName, $this->aggregateDefinitionRegistry);

        if ($identifiers === null) {
            if (! $this->doesAllowsNull) {
                throw new AggregateNotFoundException("Aggregate {$this->aggregateClassName} was not found as identifiers is null.");
            }

            return null;
        }

        $resolvedAggregate = $this->aggregateRepository->findBy(
            $this->aggregateClassName,
            $identifiers
        );

        if (! $resolvedAggregate && ! $this->doesAllowsNull) {
            $identifiersString = is_array($identifiers) ? json_encode($identifiers) : (string) $identifiers;
            throw new AggregateNotFoundException("Aggregate {$this->aggregateClassName} was not found for identifiers {$identifiersString}.");
        }

        return $resolvedAggregate?->getAggregateInstance();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function identifiersFrom(mixed $resolvedIdentifiers, string $aggregateClassName, AggregateDefinitionRegistry $aggregateDefinitionRegistry): ?array
    {
        if ($resolvedIdentifiers === null) {
            return null;
        }

        if (is_array($resolvedIdentifiers)) {
            return $resolvedIdentifiers;
        }

        $identifierMapping = $aggregateDefinitionRegistry->getFor($aggregateClassName)->getAggregateIdentifierMapping();
        if (count($identifierMapping) > 1) {
            throw new InvalidArgumentException("Can't fetch aggregate {$aggregateClassName} as it has multiple identifiers. Please provide array of identifiers.");
        }

        return [array_key_first($identifierMapping) => $resolvedIdentifiers];
    }

    private function resolveIdentifiers(Message $message): mixed
    {
        return $this->expressionExecutor->execute($message, ['value' => $message->getPayload()]);
    }
}
