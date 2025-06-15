<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter;

use Ecotone\Messaging\Config\LicenceDecider;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\AggregateNotFoundException;
use Ecotone\Modelling\Repository\AllAggregateRepository;

/**
 * licence Enterprise
 */
class FetchAggregateConverter implements ParameterConverter
{
    public function __construct(
        private AllAggregateRepository $aggregateRepository,
        private ExpressionEvaluationService $expressionEvaluationService,
        private string $aggregateClassName,
        private string $expression,
        private bool $doesAllowsNull,
        private LicenceDecider $licenceDecider,
    ) {
    }

    public function getArgumentFrom(Message $message): ?object
    {
        if (! $this->licenceDecider->hasEnterpriseLicence()) {
            throw LicensingException::create('FetchAggregate attribute is available as part of Ecotone Enterprise.');
        }

        $identifier = $this->expressionEvaluationService->evaluate(
            $this->expression,
            [
                'value' => $message->getPayload(),
                'headers' => $message->getHeaders()->headers(),
                'payload' => $message->getPayload(),
            ],
        );

        if ($identifier === null) {
            if (! $this->doesAllowsNull) {
                throw new AggregateNotFoundException("Aggregate {$this->aggregateClassName} was not found as identifier is null.");
            }

            return null;
        }

        $resolvedAggregate = $this->aggregateRepository->findBy(
            $this->aggregateClassName,
            [$identifier]
        );

        if (! $resolvedAggregate && ! $this->doesAllowsNull) {
            throw new AggregateNotFoundException("Aggregate {$this->aggregateClassName} was not found for identifier {$identifier}.");
        }

        return $resolvedAggregate?->getAggregateInstance();
    }
}
