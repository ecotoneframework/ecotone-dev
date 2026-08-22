<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use Ecotone\Messaging\ConfigurationVariableService;
use Tempest\Container\Container;

use function Tempest\env;

/**
 * licence Apache-2.0
 */
final class TempestConfigurationVariableService implements ConfigurationVariableService
{
    public function __construct(private Container $container)
    {
    }

    public function getByName(string $name): mixed
    {
        if (str_contains($name, '::')) {
            [$class, $property] = explode('::', $name, 2);

            return $this->container->get($class)->{$property};
        }

        return env($name);
    }

    public function hasName(string $name): bool
    {
        if (str_contains($name, '::')) {
            [$class, $property] = explode('::', $name, 2);

            return class_exists($class) && property_exists($class, $property);
        }

        return getenv($name) !== false;
    }
}
