<?php

declare(strict_types=1);

namespace Ecotone\SymfonyContainer;

/**
 * Answers whether the host framework's container could provide a service,
 * WITHOUT instantiating it. Used for boot-time validation of the external
 * references wired into the compiled messaging container — a get()-based
 * probe would eagerly construct heavy services (database connections,
 * mailer transports) just to prove they exist.
 *
 * licence Apache-2.0
 */
interface ExternalServiceAvailabilityProbe
{
    public function canProvide(string $id): bool;
}
