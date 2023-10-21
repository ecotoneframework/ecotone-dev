<?php

namespace Monorepo\Benchmark;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Illuminate\Support\Facades\Artisan;
use Monorepo\ExampleApp\Symfony\Kernel as SymfonyKernel;
use Psr\Container\ContainerInterface;

abstract class FullAppBenchmarkCase
{
    public function bench_symfony_prod()
    {
        $this->productionEnvironments();
        $kernel = new SymfonyKernel('prod', false);
        $kernel->boot();
        $container = $kernel->getContainer();

        $this->executeForSymfony($container, $kernel);
    }

    public function bench_symfony_dev()
    {
        $this->developmentEnvironments();
        $kernel = new SymfonyKernel('dev', true);
        $kernel->boot();
        $container = $kernel->getContainer();

        $this->executeForSymfony($container, $kernel);
    }

    /**
     * @BeforeMethods("dumpLaravelCache")
     * @AfterMethods("clearLaravelCache")
     */
    public function bench_laravel_prod(): void
    {
        $this->productionEnvironments();
        $app = $this->createLaravelApplication();

        $this->executeForLaravel($app, $app->get(LaravelKernel::class));
    }

    public function bench_laravel_dev(): void
    {
        $this->developmentEnvironments();
        $app = $this->createLaravelApplication();

        $this->executeForLaravel($app, $app->get(LaravelKernel::class));
    }

    /**
     * Calling config:cache always dumps the cache,
     * this means we need to do it before the benchmark
     */
    public function dumpLaravelCache(): void
    {
        $this->productionEnvironments();
        $this->createLaravelApplication();
        Artisan::call('route:cache');
        Artisan::call('config:cache');
    }

    public function clearLaravelCache(): void
    {
        $this->productionEnvironments();
        $this->createLaravelApplication();
        Artisan::call('config:clear');
    }

    public function bench_lite_prod()
    {
        $this->productionEnvironments();
        $bootstrap = require __DIR__ . "/../ExampleApp/Lite/app.php";
        $messagingSystem =  $bootstrap(true);
        $this->executeForLite(new LiteContainerAccessor($messagingSystem));
    }

    public function bench_lite_dev()
    {
        $this->developmentEnvironments();
        $bootstrap = require __DIR__ . "/../ExampleApp/Lite/app.php";
        $messagingSystem =  $bootstrap(false);
        $this->executeForLite(new LiteContainerAccessor($messagingSystem));
    }

    private function createLaravelApplication(): Application
    {
        $app = require __DIR__ . '/../ExampleApp/Laravel/bootstrap/app.php';

        $app->make(LaravelKernel::class)->bootstrap();

        return $app;
    }

    public abstract function executeForSymfony(
        ContainerInterface $container,
        SymfonyKernel $kernel
    ): void;

    public abstract function executeForLaravel(
        ContainerInterface $container,
        LaravelKernel $kernel
    ): void;

    public abstract function executeForLite(
        ContainerInterface $container
    ): void;

    private function productionEnvironments(): void
    {
        \putenv('APP_ENV=prod');
        \putenv('APP_DEBUG=false');
    }

    private function developmentEnvironments(): void
    {
        \putenv('APP_ENV=dev');
        \putenv('APP_DEBUG=true');
    }
}