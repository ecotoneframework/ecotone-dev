<?php

namespace Monorepo\Benchmark;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Illuminate\Support\Facades\Artisan;
use Monorepo\ExampleApp\Symfony\Kernel as SymfonyKernel;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application as SymfonyConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @BeforeClassMethods("setUpBeforeClass")
 */
abstract class FullAppBenchmarkCase extends TestCase
{
    public function bench_symfony_prod()
    {
        self::productionEnvironments();
        $kernel = self::bootSymfonyKernel(environment: 'prod', debug: false);
        $container = $kernel->getContainer();

        $this->executeForSymfony($container, $kernel);
    }

    public function bench_symfony_dev()
    {
        self::developmentEnvironments();
        $kernel = self::bootSymfonyKernel(environment: 'dev', debug: true);
        $container = $kernel->getContainer();

        $this->executeForSymfony($container, $kernel);
    }

    /**
     * @BeforeMethods("dumpLaravelCache")
     */
    public function bench_laravel_prod(): void
    {
        self::productionEnvironments();
        $app = self::createLaravelApplication();

        $this->executeForLaravel($app, $app->get(LaravelKernel::class));
    }

    public function bench_laravel_dev(): void
    {
        self::developmentEnvironments();
        $app = self::createLaravelApplication();

        $this->executeForLaravel($app, $app->get(LaravelKernel::class));
    }

    public function bench_lite_application_prod()
    {
        self::productionEnvironments();
        $bootstrap = require __DIR__ . "/../ExampleApp/LiteApplication/app.php";
        $messagingSystem =  $bootstrap(true);
        $this->executeForLiteApplication(new LiteContainerAccessor($messagingSystem));
    }

    public function bench_lite_application_dev()
    {
        self::developmentEnvironments();
        $bootstrap = require __DIR__ . "/../ExampleApp/LiteApplication/app.php";
        $messagingSystem =  $bootstrap(false);
        $this->executeForLiteApplication(new LiteContainerAccessor($messagingSystem));
    }

    public function bench_lite_prod()
    {
        self::productionEnvironments();
        $bootstrap = require __DIR__ . '/../ExampleApp/Lite/app.php';
        $messagingSystem = $bootstrap(true);
        $this->executeForLite($messagingSystem);
    }

    public function bench_lite_dev()
    {
        self::developmentEnvironments();
        $bootstrap = require __DIR__ . '/../ExampleApp/Lite/app.php';
        $messagingSystem = $bootstrap(false);
        $this->executeForLite($messagingSystem);
    }

    public static function setUpBeforeClass(): void
    {
        self::clearLaravelCache();
        self::clearSymfonyCache();
        self::clearLiteApplicationCache();
        self::clearLiteCache();
    }

    public static function clearLiteCache(): void
    {
        self::deleteFiles(
            __DIR__ . '/../ExampleApp/Lite/var/cache',
            false
        );
    }

    public static function clearLiteApplicationCache(): void
    {
        self::deleteFiles(
            __DIR__ . '/../ExampleApp/LiteApplication/var/cache',
            false
        );
    }

    /**
     * Calling config:cache always dumps the cache,
     * this means we need to do it before the benchmark
     */
    public function dumpLaravelCache(): void
    {
        self::productionEnvironments();
        self::createLaravelApplication();
        Artisan::call('route:cache');
        Artisan::call('config:cache');
    }

    public static function clearLaravelCache(): void
    {
        self::deleteFiles(
            __DIR__ . '/../ExampleApp/Laravel/storage/framework/cache/data',
            false
        );
    }

    public static function clearSymfonyCache(): void
    {
        self::deleteFiles(
            __DIR__ . '/../ExampleApp/Symfony/var/cache',
            false
        );
    }

    private static function deleteFiles(string $target, bool $deleteDirectory): void
    {
        if (is_dir($target)) {
            \Ecotone\Messaging\Support\Assert::isTrue(
                is_writable($target),
                "Not enough permissions to delete from cache directory {$target}"
            );
            $files = glob($target . '*', GLOB_MARK);

            foreach ($files as $file) {
                self::deleteFiles($file, true);
            }

            if ($deleteDirectory) {
                rmdir($target);
            }
        } elseif (is_file($target)) {
            Assert::isTrue(
                is_writable($target),
                "Not enough permissions to delete cache file {$target}"
            );
            unlink($target);
        }
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

    private static function productionEnvironments(): void
    {
        \putenv('APP_ENV=prod');
        \putenv('APP_DEBUG=false');
    }

    private static function developmentEnvironments(): void
    {
        \putenv('APP_ENV=dev');
        \putenv('APP_DEBUG=true');
    }

    private static function createLaravelApplication(): Application
    {
        $app = require __DIR__ . '/../ExampleApp/Laravel/bootstrap/app.php';

        $app->make(LaravelKernel::class)->bootstrap();

        return $app;
    }

    private static function bootSymfonyKernel(string $environment, bool $debug): SymfonyKernel
    {
        $kernel = new SymfonyKernel($environment, $debug);
        $kernel->boot();
        return $kernel;
    }

    public static function executeSymfonyConsoleCommand(SymfonyKernel $kernel, string $commandName, array $input): void
    {
        $application = new SymfonyConsoleApplication($kernel);
        $result = $application->find($commandName)->run(
            new ArrayInput($input),
            new NullOutput()
        );

        Assert::assertSame(0, $result);
    }
}