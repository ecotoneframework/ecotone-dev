<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests\Tempest;

use Ecotone\Api\Tempest\TempestConnectionReference;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConnectionReferenceCredentialsTest extends TestCase
{
    public function test_default_connection_has_no_tag(): void
    {
        $reference = TempestConnectionReference::defaultConnection();

        $this->assertNull($reference->configTag);
        $this->assertSame(DbalConnectionFactory::class, $reference->getReferenceName());
    }
}
