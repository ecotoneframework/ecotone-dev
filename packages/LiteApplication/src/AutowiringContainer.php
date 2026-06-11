<?php

declare(strict_types=1);

namespace Ecotone\Lite;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * licence Apache-2.0
 */
final class AutowiringContainer implements ContainerInterface
{
    private array $resolvedObjects = [];

    private ?ContainerInterface $ecotoneContainer = null;

    public function __construct(private ContainerInterface $innerContainer)
    {
    }

    public function setEcotoneContainer(ContainerInterface $ecotoneContainer): void
    {
        $this->ecotoneContainer = $ecotoneContainer;
    }

    public function get(string $id): mixed
    {
        if ($this->innerContainer->has($id)) {
            return $this->innerContainer->get($id);
        }
        if (isset($this->resolvedObjects[$id])) {
            return $this->resolvedObjects[$id];
        }

        return $this->resolvedObjects[$id] = $this->instantiate($id);
    }

    public function has(string $id): bool
    {
        if ($this->innerContainer->has($id)) {
            return true;
        }
        if (! class_exists($id)) {
            return false;
        }

        return (new ReflectionClass($id))->isInstantiable();
    }

    private function instantiate(string $className): object
    {
        if (! class_exists($className) || ! (new ReflectionClass($className))->isInstantiable()) {
            throw new InvalidArgumentException("Service {$className} is not registered and can not be auto-wired");
        }

        $reflection = new ReflectionClass($className);
        $constructor = $reflection->getConstructor();
        if (! $constructor) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $typeName = $type->getName();
                if ($this->innerContainer->has($typeName)) {
                    $arguments[] = $this->innerContainer->get($typeName);
                    continue;
                }
                if ($this->ecotoneContainer?->has($typeName)) {
                    $arguments[] = $this->ecotoneContainer->get($typeName);
                    continue;
                }
                if ($this->has($typeName)) {
                    $arguments[] = $this->get($typeName);
                    continue;
                }
            }
            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new InvalidArgumentException("Can not auto-wire parameter {$parameter->getName()} of {$className}");
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
