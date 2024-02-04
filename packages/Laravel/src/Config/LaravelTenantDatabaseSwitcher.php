<?php

declare(strict_types=1);

namespace Ecotone\Laravel\Config;

use Ecotone\Messaging\Attribute\ServiceActivator;
use Ecotone\Messaging\Config\ConnectionReference;
use Ecotone\Dbal\MultiTenant\HeaderBasedMultiTenantConnectionFactory;
use Illuminate\Support\Facades\Config;

final class LaravelTenantDatabaseSwitcher
{
    public function __construct(
        private string $defaultConnectionName,
    )
    {

    }

    public static function create(): self
    {
        return new self(Config::get('database.default'));
    }

    #[ServiceActivator(HeaderBasedMultiTenantConnectionFactory::TENANT_ACTIVATED_CHANNEL_NAME)]
    public function switchOn(string|ConnectionReference $activatedConnection): void
    {
        if ($activatedConnection instanceof LaravelConnectionReference) {
            Config::set('database.default', $activatedConnection->getLaravelConnectionName());
        }
    }

    #[ServiceActivator(HeaderBasedMultiTenantConnectionFactory::TENANT_DEACTIVATED_CHANNEL_NAME)]
    public function switchOff(): void
    {
        Config::set('database.default', $this->defaultConnectionName);
    }
}