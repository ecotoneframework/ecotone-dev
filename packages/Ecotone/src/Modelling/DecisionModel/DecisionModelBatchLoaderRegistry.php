<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use function sprintf;

/**
 * licence Enterprise
 */
final class DecisionModelBatchLoaderRegistry
{
    public static function serviceIdFor(string $className, string $methodName): string
    {
        return sprintf('decisionModel.batchLoader.%s::%s', $className, $methodName);
    }
}
