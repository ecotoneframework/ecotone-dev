<?php

namespace Test\Ecotone\Laravel\Application\Benchmark;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class EcotoneBenchmark extends TestCase
{
    /**
     * @Revs(10)
     * @Iterations(5)
     * @Warmup(1)
     */
    public function bench_kernel_boot_on_prod()
    {
        putenv('APP_ENV=production');
        $this->createApplication();
    }

    /**
     * @Revs(10)
     * @Iterations(5)
     * @Warmup(1)
     */
    public function bench_kernel_boot_on_dev()
    {
        putenv('APP_ENV=development');
        $this->createApplication();
    }

    /**
     * @Revs(10)
     * @Iterations(5)
     * @Warmup(1)
     */
    public function bench_messaging_boot_on_prod()
    {
        putenv('APP_ENV=production');
        $app = $this->createApplication();
        $app->make(ConfiguredMessagingSystem::class);
    }

    /**
     * @Revs(10)
     * @Iterations(5)
     * @Warmup(1)
     */
    public function bench_messaging_boot_on_dev()
    {
        putenv('APP_ENV=development');
        $app = $this->createApplication();
        $app->make(ConfiguredMessagingSystem::class);
    }

    public function createApplication(): Application
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
