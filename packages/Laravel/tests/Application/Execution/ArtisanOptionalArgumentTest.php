<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Application\Execution;

use Ecotone\Laravel\EcotoneCacheClear;
use Ecotone\Laravel\EcotoneProvider;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;

/**
 * licence Apache-2.0
 * @internal
 */
final class ArtisanOptionalArgumentTest extends TestCase
{
    public function createApplication()
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        EcotoneCacheClear::clearEcotoneCacheDirectories(EcotoneProvider::getCacheDirectoryPath());

        return $app;
    }

    public function test_an_optional_console_argument_without_a_default_reaches_the_command_through_artisan(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no projection with name unknown_projection');

        Artisan::call('ecotone:projection:init', ['name' => 'unknown_projection']);
    }
}
