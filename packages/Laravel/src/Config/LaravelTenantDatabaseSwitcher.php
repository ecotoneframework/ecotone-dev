<?php

declare(strict_types=1);

namespace Ecotone\Laravel\Config;

use Ecotone\Dbal\MultiTenant\HeaderBasedMultiTenantConnectionFactory;
use Ecotone\Messaging\Attribute\ServiceActivator;
use Ecotone\Messaging\Config\ConnectionReference;
use Illuminate\Support\Facades\Config;

final class LaravelTenantDatabaseSwitcher
{
    public function __construct(
        private string $defaultConnectionName,
    ) {

    }

    public static function create(): self
    {
        return new self(Config::get('database.default'));
    }

    public function switchOn(string|ConnectionReference $activatedConnection): void
    {
        if ($activatedConnection instanceof LaravelConnectionReference) {
            Config::set('database.default', $activatedConnection->getLaravelConnectionName());
        }
    }

    public function switchOff(): void
    {
        Config::set('database.default', $this->defaultConnectionName);
    }
}
