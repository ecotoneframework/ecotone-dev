<?php

namespace Monorepo\Benchmark;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
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
        self::productionEnvironments();
        MessagingSystemConfiguration::cleanCache(
            new ServiceCacheConfiguration(
                __DIR__ . '/../ExampleApp/Lite/var/cache',
                true
            )
        );
    }

    public static function clearLiteApplicationCache(): void
    {
        self::productionEnvironments();
        MessagingSystemConfiguration::cleanCache(
            new ServiceCacheConfiguration(
                __DIR__ . '/../ExampleApp/LiteApplication/var/cache',
                true
            )
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
        self::productionEnvironments();
        MessagingSystemConfiguration::cleanCache(
            new ServiceCacheConfiguration(
                __DIR__ . '/../ExampleApp/Laravel/storage/framework/cache/data',
                true
            )
        );
    }

    public static function clearSymfonyCache(): void
    {
        self::productionEnvironments();
        $kernel = self::bootSymfonyKernel(environment: 'prod', debug: false);
        $input = [];
        $commandName = 'cache:clear';

        self::executeSymfonyConsoleCommand($kernel, $commandName, $input);

        self::developmentEnvironments();
        $kernel = self::bootSymfonyKernel(environment: 'dev', debug: true);

        self::executeSymfonyConsoleCommand($kernel, $commandName, $input);
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

    public static function runConsumerForSymfony(string $consumerName, SymfonyKernel $kernel, bool $stopOnFailure = true): void
    {
        self::executeSymfonyConsoleCommand(
            $kernel,
            'ecotone:run',
            ['consumerName' => $consumerName, '--stopOnFailure' => $stopOnFailure, '--executionTimeLimit' => 2000, '--finishWhenNoMessages' => true]
        );
    }

    public static function runConsumerForLaravel(string $consumerName, bool $stopOnFailure = true): void
    {
        Artisan::call(
            'ecotone:run',
            ['consumerName' => $consumerName, '--stopOnFailure' => $stopOnFailure, '--executionTimeLimit' => 2000, '--finishWhenNoMessages' => true]
        );
    }

    public static function runConsumerForMessaging(string $consumerName, ConfiguredMessagingSystem $messagingSystem, bool $stopOnFailure = true): void
    {
        $messagingSystem->run(
            $consumerName,
            ExecutionPollingMetadata::createWithFinishWhenNoMessages($stopOnFailure)
                ->withExecutionTimeLimitInMilliseconds(2000)
        );
    }
}