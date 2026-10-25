<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Application\Execution;

use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Laravel\EcotoneCacheClear;
use Ecotone\Laravel\EcotoneProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Laravel\RestoresLaravelHandlers;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConnectionExceptionRetryTest extends TestCase
{
    use RestoresLaravelHandlers;


    public function test_consumer_reconnects_as_many_times_as_max_attempts_in_default_connection_exception_retry(): void
    {
        $app = $this->createApplication();
        $app->make(CommandBus::class)->sendWithRouting('connection_retry.four_times', 'order-1');

        Artisan::call('ecotone:run', ['consumerName' => 'connection_lost_four_times', '--handledMessageLimit' => 1, '--executionTimeLimit' => 5000]);

        $this->assertSame(['order-1'], $app->make(QueryBus::class)->sendWithRouting('connection_retry.handled'));
    }

    public function test_consumer_gives_up_once_max_attempts_in_default_connection_exception_retry_are_used(): void
    {
        $app = $this->createApplication();
        $app->make(CommandBus::class)->sendWithRouting('connection_retry.five_times', 'order-1');

        $gaveUp = false;
        try {
            Artisan::call('ecotone:run', ['consumerName' => 'connection_lost_five_times', '--handledMessageLimit' => 1, '--executionTimeLimit' => 5000]);
        } catch (Throwable) {
            $gaveUp = true;
        }

        $this->assertTrue($gaveUp);
        $this->assertSame([], $app->make(QueryBus::class)->sendWithRouting('connection_retry.handled'));
    }

    private function createApplication(): Application
    {
        $app = require __DIR__ . '/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        EcotoneCacheClear::clearEcotoneCacheDirectories(EcotoneProvider::getCacheDirectoryPath());

        return $app;
    }
}
