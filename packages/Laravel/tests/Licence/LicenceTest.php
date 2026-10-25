<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Licence;

use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Laravel\EcotoneCacheClear;
use Ecotone\Laravel\EcotoneProvider;
use Ecotone\Test\LicenceTesting;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Laravel\RestoresLaravelHandlers;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class LicenceTest extends TestCase
{
    use RestoresLaravelHandlers;

    private Application $app;
    private QueryBus $queryBus;
    private CommandBus $commandBus;

    public function setUp(): void
    {
        putenv('LARAVEL_LICENCE_KEY=' . LicenceTesting::VALID_LICENCE);

        $app = require __DIR__ . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        EcotoneCacheClear::clearEcotoneCacheDirectories(EcotoneProvider::getCacheDirectoryPath());

        $this->app = $app;
        $this->queryBus = $app->get(QueryBus::class);
        $this->commandBus = $app->get(CommandBus::class);
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
