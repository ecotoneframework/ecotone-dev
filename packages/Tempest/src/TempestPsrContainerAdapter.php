<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use Tempest\Container\Container;

/**
 * licence Apache-2.0
 */
final class TempestPsrContainerAdapter implements ContainerInterface
{
    public function __construct(private Container $container)
    {
    }

    public function get(string $id): mixed
    {
        return $this->container->get($id);
    }

    public function has(string $id): bool
    {
        if ($this->container->has($id)) {
            return true;
        }

        if (! class_exists($id) && ! interface_exists($id)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($id);
            return $reflection->isInstantiable();
        } catch (ReflectionException) {
            return false;
        }
    }
}
