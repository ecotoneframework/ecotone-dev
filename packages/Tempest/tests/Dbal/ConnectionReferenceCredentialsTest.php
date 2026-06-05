<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Dbal;

use Ecotone\Tempest\Config\TempestConnectionReference;
use PHPUnit\Framework\TestCase;
use Tempest\Database\Config\PostgresConfig;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConnectionReferenceCredentialsTest extends TestCase
{
    public function test_create_builds_reference_with_provided_database_config(): void
    {
        $config = new PostgresConfig(
            host: 'database',
            port: '5432',
            username: 'ecotone',
            password: 'secret',
            database: 'ecotone',
        );

        $reference = TempestConnectionReference::create('tenant_a', $config);

        $this->assertSame('tenant_a', $reference->getReferenceName());
        $this->assertSame($config, $reference->getDatabaseConfig());
    }

    public function test_get_definition_round_trips_reference_name_and_database_config(): void
    {
        $config = new PostgresConfig(
            host: 'database',
            port: '5432',
            username: 'ecotone',
            password: 'secret',
            database: 'ecotone',
        );

        $reference = TempestConnectionReference::create('tenant_a', $config);
        $definition = $reference->getDefinition();

        $reconstructed = TempestConnectionReference::createFromSerializedConfig(
            ...$definition->getArguments()
        );

        $this->assertSame('tenant_a', $reconstructed->getReferenceName());

        $reconstructedConfig = $reconstructed->getDatabaseConfig();
        $this->assertNotNull($reconstructedConfig);
        $this->assertSame('database', $reconstructedConfig->host);
        $this->assertSame('ecotone', $reconstructedConfig->database);
        $this->assertSame('ecotone', $reconstructedConfig->username);
        $this->assertSame('secret', $reconstructedConfig->password);
    }

    public function test_default_connection_has_no_embedded_database_config(): void
    {
        $reference = TempestConnectionReference::defaultConnection();

        $this->assertNull($reference->getDatabaseConfig());
    }

    public function test_create_without_config_produces_null_database_config(): void
    {
        $reference = TempestConnectionReference::create('some_connection');

        $this->assertNull($reference->getDatabaseConfig());
    }
}
