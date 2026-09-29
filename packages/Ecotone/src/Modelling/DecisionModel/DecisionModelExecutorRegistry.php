<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use function sprintf;

/**
 * licence Enterprise
 */
final class DecisionModelExecutorRegistry
{
    public static function serviceIdFor(string $modelClassName): string
    {
        return sprintf('decisionModel.eventSourcingExecutor.%s', $modelClassName);
    }
}
