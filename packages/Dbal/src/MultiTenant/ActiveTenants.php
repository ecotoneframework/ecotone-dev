<?php

declare(strict_types=1);

namespace Ecotone\Dbal\MultiTenant;

/**
 * licence Apache-2.0
 */
final class ActiveTenants
{
    public ?string $currentActiveTenant = null;

    public function __construct(public ?string $pollingConsumerTenant)
    {
    }
}
