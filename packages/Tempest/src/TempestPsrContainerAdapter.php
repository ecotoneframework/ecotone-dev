<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use ArrayIterator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Tempest\Container\Container;
use Tempest\Container\DynamicInitializer;
use Tempest\Container\GenericContainer;
use Tempest\Reflection\ClassReflector;
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

    /**
     * has() is a capability question and must never construct a service —
     * it runs during messaging-system compilation, where resolving would
     * e.g. open a database connection. Tempest's Container::has() covers
     * definitions and singletons only, so services provided by Initializer /
     * DynamicInitializer classes (e.g. Tempest\Mail\Mailer) are consulted
     * through the container's registries — a pure lookup. Concrete
     * instantiable classes count as available because Tempest autowires them
     * on demand; whether construction succeeds is answered at dispatch time.
     */
    public function has(string $id): bool
    {
        $id = $this->mapServiceId($id);
        if ($this->container->has($id)) {
            return true;
        }

        if (! class_exists($id) && ! interface_exists($id)) {
            return false;
        }

        if ($this->isProvidedByInitializer($id)) {
            return true;
        }

        try {
            return (new ReflectionClass($id))->isInstantiable();
        } catch (ReflectionException) {
            return false;
        }
    }

    private function isProvidedByInitializer(string $id): bool
    {
        if (! $this->container instanceof GenericContainer) {
            return false;
        }

        if ($this->initializerRegistry()->offsetExists($id)) {
            return true;
        }

        foreach ($this->dynamicInitializerClasses() as $initializerClass) {
            try {
                $initializer = $this->container->get($initializerClass);
                \assert($initializer instanceof DynamicInitializer);

                if ($initializer->canInitialize(new ClassReflector($id), null)) {
                    return true;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }

    private function initializerRegistry(): ArrayIterator
    {
        $initializers = new ReflectionProperty(GenericContainer::class, 'initializers')->getValue($this->container);

        return $initializers instanceof ArrayIterator ? $initializers : new ArrayIterator((array) $initializers);
    }

    /**
     * @return string[]
     */
    private function dynamicInitializerClasses(): array
    {
        $dynamicInitializers = new ReflectionProperty(GenericContainer::class, 'dynamicInitializers')->getValue($this->container);

        return $dynamicInitializers instanceof ArrayIterator
            ? array_values($dynamicInitializers->getArrayCopy())
            : array_values((array) $dynamicInitializers);
    }

    private function mapServiceId(string $id): string
    {
        return $id === 'logger' ? LoggerInterface::class : $id;
    }
}
