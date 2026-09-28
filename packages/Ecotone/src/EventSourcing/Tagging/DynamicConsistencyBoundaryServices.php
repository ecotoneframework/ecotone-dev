<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\LicenceDecider;

/**
 * licence Enterprise
 */
final class DynamicConsistencyBoundaryServices
{
    public static function definitionFor(bool $dynamicConsistencyBoundaryEnabled, string $className, string $openCoreServiceReference, string $enterpriseServiceReference): Definition|Reference
    {
        if (! $dynamicConsistencyBoundaryEnabled) {
            return Reference::to($openCoreServiceReference);
        }

        return LicenceDecider::prepareDefinition($className, $openCoreServiceReference, $enterpriseServiceReference);
    }
}
