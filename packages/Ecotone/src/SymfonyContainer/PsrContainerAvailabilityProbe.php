<?php

declare(strict_types=1);

namespace Ecotone\SymfonyContainer;

use Psr\Container\ContainerInterface;

/**
 * Availability probe for a plain PSR-11 external container (EcotoneLite).
 * Relies solely on has() — the PSR-11 capability contract — and deliberately
 * has NO autowire fallback: Lite resolves references exclusively from the
 * provided container, so an instantiable-but-unregistered class is still
 * unavailable at runtime.
 *
 * licence Apache-2.0
 */
final class PsrContainerAvailabilityProbe implements ExternalServiceAvailabilityProbe
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function canProvide(string $id): bool
    {
        return $this->container->has($id);
    }
}
