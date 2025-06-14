<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Licence;

use Ecotone\Laravel\EcotoneCacheClear;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Ecotone\Test\LicenceTesting;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class LicenceTest extends TestCase
{
    private Application $app;
    private QueryBus $queryBus;
    private CommandBus $commandBus;

    public function setUp(): void
    {
        putenv('LARAVEL_LICENCE_KEY=' . LicenceTesting::VALID_LICENCE);

        $app = require __DIR__ . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $this->app = $app;
        $this->queryBus = $app->get(QueryBus::class);
        $this->commandBus = $app->get(CommandBus::class);

        EcotoneCacheClear::clearEcotoneCacheDirectories($app->storagePath());
    }

    protected function tearDown(): void
    {
        putenv('LARAVEL_LICENCE_KEY');
    }

    public function test_triggering_laravel_with_licence_key(): void
    {
        $this->commandBus->sendWithRouting('sendNotification');

        $this->expectNotToPerformAssertions();
    }
}
