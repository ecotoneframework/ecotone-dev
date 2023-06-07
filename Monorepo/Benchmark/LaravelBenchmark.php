<?php

namespace Monorepo\Benchmark;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use PhpBench\Benchmark\Metadata\Annotations\Iterations;
use PhpBench\Benchmark\Metadata\Annotations\Revs;
use PhpBench\Benchmark\Metadata\Annotations\Warmup;

/**
 * @Revs(10)
 * @Iterations(5)
 * @Warmup(1)
 */
class LaravelBenchmark
{
    public function bench_kernel_boot_on_prod()
    {
        putenv('APP_ENV=production');
        $this->createApplication();
    }

    public function bench_kernel_boot_on_dev()
    {
        putenv('APP_ENV=development');
        $this->createApplication();
    }

    public function bench_messaging_boot_on_prod()
    {
        putenv('APP_ENV=production');
        $app = $this->createApplication();
        $app->make(ConfiguredMessagingSystem::class);
    }

    public function bench_messaging_boot_on_dev()
    {
        putenv('APP_ENV=development');
        $app = $this->createApplication();
        $app->make(ConfiguredMessagingSystem::class);
    }

    public function createApplication(): Application
    {
        $app = require \dirname(__DIR__, 1) . '/ExampleApp/Laravel//bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
