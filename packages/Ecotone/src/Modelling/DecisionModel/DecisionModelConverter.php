<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Handler\ParameterConverter;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
