<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\EventBus;
use Ecotone\Modelling\QueryBus;
use Tempest\Container\Container;
use Tempest\Container\DynamicInitializer;
use Tempest\Reflection\ClassReflector;
use UnitEnum;

/**
 * licence Apache-2.0
 */
final class EcotoneServiceInitializer implements DynamicInitializer
{
    private static ?array $compiledServiceIds = null;

    public static function clearCache(): void
    {
        self::$compiledServiceIds = null;
    }

    public static function markCompiled(array $serviceIds): void
    {
        self::$compiledServiceIds = array_flip($serviceIds);
    }

    public function canInitialize(ClassReflector $class, null|string|UnitEnum $tag): bool
    {
        if (self::$compiledServiceIds !== null) {
            return isset(self::$compiledServiceIds[$class->getName()]);
        }

        return $this->isKnownEcotoneGateway($class->getName());
    }

    public function initialize(ClassReflector $class, null|string|UnitEnum $tag, Container $container): object
    {
        $configuredMessagingSystem = $container->get(ConfiguredMessagingSystem::class);

        return $configuredMessagingSystem->getGatewayByName($class->getName());
    }

    private function isKnownEcotoneGateway(string $className): bool
    {
        return in_array($className, [
            CommandBus::class,
            QueryBus::class,
            EventBus::class,
        ], true);
    }
}
