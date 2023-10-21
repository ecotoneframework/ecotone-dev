<?php

namespace Monorepo\CrossModuleTests\Tests;

use Monorepo\ExampleApp\Symfony\Kernel;
use Psr\Container\ContainerInterface;

abstract class FullAppTestCase
{
    public abstract function getSkippedModules(): array;

    public function test_symfony_prod()
    {
        \putenv('APP_ENV=prod');
        $kernel = new Kernel('prod', false);
        $kernel->
        $kernel->boot();
        $container = $kernel->getContainer();

        $this->execute($container);

        $kernel->shutdown();
    }

    public function test_symfony_dev()
    {
        \putenv('APP_ENV=dev');
        $kernel = new Kernel('dev', true);
        $kernel->boot();
        $container = $kernel->getContainer();

        $this->execute($container);

        $kernel->shutdown();
    }

    public function test_laravel_prod(): void
    {
        \putenv('APP_ENV=production');
        $app = $this->createLaravelApplication();
        $this->execute($app);
    }

    public function test_laravel_dev(): void
    {
        \putenv('APP_ENV=development');
        $app = $this->createLaravelApplication();
        $this->execute($app);
    }

    public function test_lite_prod()
    {
        $bootstrap = require __DIR__ . "/../ExampleApp/Lite/app.php";
        $messagingSystem =  $bootstrap(true);
        $this->execute(new LiteContainerAccessor($messagingSystem));
    }

    public function test_lite_dev()
    {
        $bootstrap = require __DIR__ . "/../ExampleApp/Lite/app.php";
        $messagingSystem =  $bootstrap(false);
        $this->execute(new LiteContainerAccessor($messagingSystem));
    }

    private function createLaravelApplication()
    {
        $app = require __DIR__ . '/../ExampleApp/Laravel/bootstrap/app.php';

        $app->make(\Illuminate\Foundation\Http\Kernel::class)->bootstrap();

        return $app;
    }

    protected abstract function execute(ContainerInterface $container): void;
}