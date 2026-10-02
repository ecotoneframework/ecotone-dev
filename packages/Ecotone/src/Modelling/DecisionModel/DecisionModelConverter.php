<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Handler\ParameterConverter;

/**
 * licence Enterprise
 */
final class DecisionModelConverter implements ParameterConverter
{
    public function __construct(
        private readonly string $parameterName,
    ) {
    }

    public function getArgumentFrom(Message $message): ?object
    {
        return DecisionModelLoadedState::instanceCarriedBy($message, $this->parameterName);
    }
}
