<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use Tempest\Container\Container;
use Throwable;

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
        return $this->container->get($this->mapServiceId($id));
    }

    public function has(string $id): bool
    {
        $id = $this->mapServiceId($id);
        if ($this->container->has($id)) {
            return true;
        }

        if ($id === LoggerInterface::class) {
            try {
                $this->container->get($id);
                return true;
            } catch (Throwable) {
                return false;
            }
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

    private function mapServiceId(string $id): string
    {
        return $id === 'logger' ? LoggerInterface::class : $id;
    }
}
