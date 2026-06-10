<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Dbal;

use Ecotone\Tempest\Config\TempestConnectionReference;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConnectionReferenceCredentialsTest extends TestCase
{
    public function test_create_stores_only_tag_name_not_credentials(): void
    {
        $reference = TempestConnectionReference::create('tenant_a');

        $this->assertSame('tenant_a', $reference->getConfigTag());
        $this->assertSame('tenant_a', $reference->getReferenceName());
    }

    public function test_create_with_custom_reference_name(): void
    {
        $reference = TempestConnectionReference::create('tenant_a', 'custom_ref');

        $this->assertSame('tenant_a', $reference->getConfigTag());
        $this->assertSame('custom_ref', $reference->getReferenceName());
    }

    public function test_get_definition_serializes_only_tag_name_no_credentials(): void
    {
        $reference = TempestConnectionReference::create('tenant_a');
        $definition = $reference->getDefinition();

        $args = $definition->getArguments();
        $this->assertSame('tenant_a', $args[0]);
        $this->assertSame('tenant_a', $args[1]);
        foreach ($args as $arg) {
            $this->assertIsString($arg);
        }
    }

    public function test_definition_round_trips_via_factory(): void
    {
        $reference = TempestConnectionReference::create('tenant_a');
        $definition = $reference->getDefinition();

        $reconstructed = TempestConnectionReference::fromTagAndReferenceName(
            ...$definition->getArguments()
        );

        $this->assertSame('tenant_a', $reconstructed->getConfigTag());
        $this->assertSame('tenant_a', $reconstructed->getReferenceName());
    }

    public function test_default_connection_has_no_tag(): void
    {
        $reference = TempestConnectionReference::defaultConnection();

        $this->assertNull($reference->getConfigTag());
        $this->assertSame(DbalConnectionFactory::class, $reference->getReferenceName());
    }
}
