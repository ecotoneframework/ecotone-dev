<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config;

use Ecotone\Messaging\Config\ConnectionReference;
use Tempest\Container\GenericContainer;
use Tempest\Database\Config\DatabaseConfig;
use Tempest\Database\Connection\Connection;
use Tempest\Database\Database;

/**
 * licence Apache-2.0
 */
final class TempestTenantDatabaseSwitcher
{
    public function __construct(
        private readonly DatabaseConfig $defaultDatabaseConfig,
    ) {}

    public static function create(): self
    {
        $container = GenericContainer::instance();
        $defaultConfig = $container->get(DatabaseConfig::class);

        return new self($defaultConfig);
    }

    public function switchOn(string|ConnectionReference $activatedConnection): void
    {
        if (! ($activatedConnection instanceof TempestConnectionReference)) {
            return;
        }

        $tenantConfig = $activatedConnection->getDatabaseConfig();

        if ($tenantConfig === null) {
            return;
        }

        $container = GenericContainer::instance();
        $container->singleton(DatabaseConfig::class, $tenantConfig);
        $container->unregister(Connection::class);
        $container->unregister(Database::class);
    }

    public function switchOff(): void
    {
        $container = GenericContainer::instance();
        $container->singleton(DatabaseConfig::class, $this->defaultDatabaseConfig);
        $container->unregister(Connection::class);
        $container->unregister(Database::class);
    }
}
