<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\DbalConnectionRequirement;

use Ecotone\Laravel\EcotoneCacheClear;
use Ecotone\Laravel\EcotoneProvider;
use Ecotone\Messaging\Config\ConfigurationException;
use Illuminate\Foundation\Http\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DbalConnectionRequirementTest extends TestCase
{
    protected function tearDown(): void
    {
        restore_exception_handler();
        restore_error_handler();
    }

    public function test_throws_configuration_exception_when_dbal_connection_factory_is_not_configured(): void
    {
        $exceptionThrown = false;
        $exceptionMessage = '';

        try {
            $app = require __DIR__ . '/bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();
            EcotoneCacheClear::clearEcotoneCacheDirectories(EcotoneProvider::getCacheDirectoryPath());
        } catch (ConfigurationException $e) {
            $exceptionThrown = true;
            $exceptionMessage = $e->getMessage();
        }

        self::assertTrue($exceptionThrown, 'Expected ConfigurationException to be thrown');
        self::assertStringContainsString(
            "Dbal module requires 'Enqueue\Dbal\DbalConnectionFactory' to be configured",
            $exceptionMessage
        );
    }
}

