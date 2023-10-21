<?php

namespace Monorepo\CrossModuleTests\Tests;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Illuminate\Support\Facades\Artisan;
use Monorepo\Benchmark\LiteContainerAccessor;
use Monorepo\ExampleApp\Symfony\Kernel as SymfonyKernel;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

abstract class FullAppTestCase extends TestCase
{
    public function test_symfony_prod()
    {
        $this->productionEnvironments();
        $kernel = new SymfonyKernel('prod', false);
        $kernel->boot();
        $container = $kernel->getContainer();

        $this->executeForSymfony($container, $kernel);
    }

    public function test_symfony_dev()
    {
        $this->developmentEnvironments();
        $kernel = new SymfonyKernel('dev', true);
        $kernel->boot();
        $container = $kernel->getContainer();

        $this->executeForSymfony($container, $kernel);
    }

    public function test_laravel_prod(): void
    {
        $this->productionEnvironments();
        $app = $this->createLaravelApplication();
        Artisan::call('route:cache');
        Artisan::call('config:cache');

        $this->executeForLaravel($app, $app->get(LaravelKernel::class));
    }

    public function test_laravel_dev(): void
    {
        $this->developmentEnvironments();
        $app = $this->createLaravelApplication();
        Artisan::call('config:clear');

        $this->executeForLaravel($app, $app->get(LaravelKernel::class));
    }

    public function test_lite_application_prod()
    {
        $this->productionEnvironments();
        $bootstrap = require __DIR__ . "/../../ExampleApp/LiteApplication/app.php";
        $messagingSystem =  $bootstrap(true);
        $this->executeForLiteApplication(new LiteContainerAccessor($messagingSystem));
    }

    public function test_lite_application_dev()
    {
        $this->developmentEnvironments();
        $bootstrap = require __DIR__ . "/../../ExampleApp/LiteApplication/app.php";
        $messagingSystem =  $bootstrap(false);
        $this->executeForLiteApplication(new LiteContainerAccessor($messagingSystem));
    }

    public function test_lite_prod()
    {
        $this->productionEnvironments();
        $bootstrap = require __DIR__ . '/../../ExampleApp/Lite/app.php';
        $messagingSystem = $bootstrap(true);
        $this->executeForLite($messagingSystem);
    }

    public function test_lite_dev()
    {
        $this->developmentEnvironments();
        $bootstrap = require __DIR__ . '/../../ExampleApp/Lite/app.php';
        $messagingSystem = $bootstrap(false);
        $this->executeForLite($messagingSystem);
    }

    private function createLaravelApplication(): Application
    {
        $app = require __DIR__ . '/../../ExampleApp/Laravel/bootstrap/app.php';

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

    public abstract function executeForLiteApplication(
        ContainerInterface $container
    ): void;

    public abstract function executeForLite(
        ConfiguredMessagingSystem $messagingSystem
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