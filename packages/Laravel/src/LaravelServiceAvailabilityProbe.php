<?php

declare(strict_types=1);

namespace Ecotone\Laravel;

use Ecotone\SymfonyContainer\ReflectiveAvailabilityProbe;
use Illuminate\Contracts\Foundation\Application;

/**
 * Availability probe over Laravel's container metadata. Application::bound()
 * answers from bindings, instances, aliases and the deferred-provider
 * manifest — a deferred binding reports available without the provider ever
 * loading, so nothing is instantiated. Unbound but autowirable classes are
 * accepted through constructor reflection (Laravel resolves those the same
 * way at runtime).
 *
 * licence Apache-2.0
 */
final class LaravelServiceAvailabilityProbe extends ReflectiveAvailabilityProbe
{
    public function __construct(private readonly Application $app)
    {
    }

    protected function isRegisteredInContainer(string $id): bool
    {
        return $this->app->bound($id);
    }
}
