<?php

declare(strict_types=1);

namespace Symfony\App\SingleTenant\Application\External;

/**
 * licence Apache-2.0
 */
final class ExternalRegistrationHappened
{
    public function __construct(public int $customerId, public string $name)
    {
    }
}
