<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\DbalConnectionRequirementWithConnection;

use Ecotone\Laravel\EcotoneCacheClear;
use Ecotone\Laravel\EcotoneProvider;
use Ecotone\Messaging\Config\ConfigurationException;
use Illuminate\Foundation\Http\Kernel;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DbalConnectionRequirementTest extends TestCase
{
    #[DoesNotPerformAssertions]
    public function test_does_not_throw_when_dbal_connection_is_configured(): void
    {
        $app = require __DIR__ . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        EcotoneCacheClear::clearEcotoneCacheDirectories(EcotoneProvider::getCacheDirectoryPath());
    }
}

