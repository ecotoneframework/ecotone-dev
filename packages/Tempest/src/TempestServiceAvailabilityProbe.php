<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use ArrayIterator;
use Ecotone\SymfonyContainer\ReflectiveAvailabilityProbe;
use ReflectionProperty;
use Tempest\Container\Container;
use Tempest\Container\DynamicInitializer;
use Tempest\Container\GenericContainer;
use Tempest\Reflection\ClassReflector;
use Throwable;

/**
 * Availability probe over Tempest's container metadata. Tempest's own has()
 * covers definitions and singletons only — services provided by Initializer
 * classes (e.g. Tempest\Mail\Mailer) are invisible to it. This probe also
 * consults the initializer map (keyed by return type — a pure lookup) and
 * DynamicInitializer::canInitialize() predicates, without resolving anything.
 * Must run AFTER EcotoneServiceInitializer::markCompiled(), so Ecotone's own
 * dynamic initializer answers for gateways from the compiled id set instead
 * of triggering a compile.
 *
 * licence Apache-2.0
 */
final class TempestServiceAvailabilityProbe extends ReflectiveAvailabilityProbe
{
    public function __construct(private readonly Container $container)
    {
    }

    protected function isRegisteredInContainer(string $id): bool
    {
        if ($this->container->has($id)) {
            return true;
        }

        if (! $this->container instanceof GenericContainer) {
            return false;
        }

        if ($this->initializerRegistry()->offsetExists($id)) {
            return true;
        }

        if (! class_exists($id) && ! interface_exists($id)) {
            return false;
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
}
