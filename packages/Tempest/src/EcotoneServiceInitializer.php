<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Tempest\Container\Container;
use Tempest\Container\DynamicInitializer;
use Tempest\Container\GenericContainer;
use Tempest\Reflection\ClassReflector;
use UnitEnum;

/**
 * licence Apache-2.0
 */
final class EcotoneServiceInitializer implements DynamicInitializer
{
    private static ?array $compiledServiceIds = null;

    private static bool $compiling = false;

    public static function clearCache(): void
    {
        self::$compiledServiceIds = null;
        self::$compiling = false;
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

        if (self::$compiling) {
            return false;
        }

        $container = GenericContainer::instance();

        if ($container === null) {
            return false;
        }

        self::$compiling = true;
        $container->get(ConfiguredMessagingSystem::class);
        self::$compiling = false;

        return isset(self::$compiledServiceIds[$class->getName()]);
    }

    public function initialize(ClassReflector $class, null|string|UnitEnum $tag, Container $container): object
    {
        $configuredMessagingSystem = $container->get(ConfiguredMessagingSystem::class);

        return $configuredMessagingSystem->getGatewayByName($class->getName());
    }
}
