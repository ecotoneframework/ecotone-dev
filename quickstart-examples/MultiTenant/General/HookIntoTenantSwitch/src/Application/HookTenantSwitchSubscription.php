<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use Ecotone\Dbal\Api\Attribute\OnTenantActivation;
use Ecotone\Dbal\Api\Attribute\OnTenantDeactivation;
use Ecotone\Api\Attribute\Parameter\Header;
use Ecotone\Messaging\Config\ConnectionReference;

final readonly class HookTenantSwitchSubscription
{
    #[OnTenantActivation]
    public function whenActivated(string|ConnectionReference $tenantConnectionName, #[Header('tenant')] $tenantName): void
    {
        echo sprintf("HOOKING into flow: Tenant name %s is about to be activated\n", $tenantName);
    }

    #[OnTenantDeactivation]
    public function whenDeactivated(string|ConnectionReference $tenantConnectionName, #[Header('tenant')] $tenantName): void
    {
        echo sprintf("HOOKING into flow: Tenant name %s is about to be deactivated\n", $tenantName);
    }
}