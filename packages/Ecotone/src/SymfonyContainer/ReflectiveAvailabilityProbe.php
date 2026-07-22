<?php

declare(strict_types=1);

namespace Ecotone\SymfonyContainer;

use ReflectionClass;
use ReflectionNamedType;

/**
 * Base availability probe: a service is considered providable when the host
 * container has a registration for it (framework-specific), or when it is an
 * autowirable class — instantiable AND with every required constructor
 * dependency itself providable. The recursion is what catches transitively
 * broken services (a handler collaborator whose constructor needs a class
 * that does not exist) without constructing anything.
 *
 * licence Apache-2.0
 */
abstract class ReflectiveAvailabilityProbe implements ExternalServiceAvailabilityProbe
{
    /** @var array<string, bool> */
    private array $verdicts = [];

    /** @var array<string, true> */
    private array $inProgress = [];

    final public function canProvide(string $id): bool
    {
        if (isset($this->verdicts[$id])) {
            return $this->verdicts[$id];
        }

        if (isset($this->inProgress[$id])) {
            return true;
        }

        $this->inProgress[$id] = true;

        try {
            $verdict = $this->isRegisteredInContainer($id) || $this->isAutowirable($id);
        } finally {
            unset($this->inProgress[$id]);
        }

        return $this->verdicts[$id] = $verdict;
    }

    /**
     * Framework-specific registration check. Must answer from container
     * metadata (bindings, definitions, initializers) without resolving.
     */
    abstract protected function isRegisteredInContainer(string $id): bool;

    private function isAutowirable(string $id): bool
    {
        if (! class_exists($id)) {
            return false;
        }

        $reflection = new ReflectionClass($id);

        if (! $reflection->isInstantiable()) {
            return false;
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return true;
        }

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isOptional()) {
                continue;
            }

            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || $type->allowsNull()) {
                continue;
            }

            if (! $this->canProvide($type->getName())) {
                return false;
            }
        }

        return true;
    }
}
