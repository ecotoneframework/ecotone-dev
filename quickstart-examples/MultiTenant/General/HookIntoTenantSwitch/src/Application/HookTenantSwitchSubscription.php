<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\ServiceActivator;
use Ecotone\Messaging\Config\ConnectionReference;

final readonly class HookTenantSwitchSubscription
{
    #[ServiceActivator('ecotone.multi_tenant_propagation_channel.activate')]
    public function whenActivated(string|ConnectionReference $tenantConnectionName, #[Header('tenant')] $tenantName): void
    {
        echo sprintf("HOOKING into flow: Tenant name %s is about to be activated\n", $tenantName);
    }

    #[ServiceActivator('ecotone.multi_tenant_propagation_channel.deactivate')]
    public function whenDeactivated(string|ConnectionReference $tenantConnectionName, #[Header('tenant')] $tenantName): void
    {
        echo sprintf("HOOKING into flow: Tenant name %s is about to be deactivated\n", $tenantName);
    }
}