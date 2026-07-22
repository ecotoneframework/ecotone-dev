<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening;

use const DIRECTORY_SEPARATOR;

use Ecotone\Modelling\CommandBus;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
use Ecotone\Messaging\Config\ModulePackageList;
use PHPUnit\Framework\TestCase;
use Tempest\Core\FrameworkKernel;
use Tempest\Core\KernelEvent;
use Tempest\Discovery\AutoloadDiscoveryLocations;
use Tempest\Discovery\Composer;
use Tempest\Discovery\DiscoveryConfig;
use Tempest\Discovery\DiscoveryLocation;
use Test\Ecotone\Tempest\Hardening\Fixture\InitializerService\GreetingServiceInitializer;
use Test\Ecotone\Tempest\TempestTestPaths;
use Throwable;

/**
 * Runtime failures exercised through a REAL Tempest kernel boot: registerKernel
 * -> loadComposer -> discovery -> BOOTED. Boot must stay lazy — a handler with
 * a missing dependency must NOT fail the kernel boot; dispatching through the
 * booted kernel must fail with an error naming the missing service, and
 * dispatching again must report the very same error.
 *
 * licence Apache-2.0
 * @internal
 */
final class RealBootRuntimeFailureTest extends TestCase
{
    private string $cacheDirectory;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecotone_tempest';
        $this->wipeCacheDirectory();

        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();
    }

    protected function tearDown(): void
    {
        restore_exception_handler();
        restore_error_handler();

        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();

        $this->wipeCacheDirectory();
    }

    public function test_kernel_boots_despite_missing_handler_dependency_and_dispatch_fails_honestly(): void
    {
        $kernel = $this->bootTempestKernel(
            'Test\\Ecotone\\Tempest\\Hardening\\Fixture\\MissingReference\\',
        );

        $commandBus = $kernel->container->get(CommandBus::class);

        $firstException = $this->dispatchAndCaptureFailure($commandBus);
        $this->assertStringContainsString(
            'MissingServiceContract',
            $firstException->getMessage(),
            'The dispatch error must name the unresolvable reference',
        );

        $secondException = $this->dispatchAndCaptureFailure($commandBus);
        $this->assertSame($firstException::class, $secondException::class);
        $this->assertSame(
            $firstException->getMessage(),
            $secondException->getMessage(),
            'A failed dispatch must not leave half-built services behind that change the error on the next attempt',
        );
    }

    public function test_kernel_boots_and_handler_executes_when_service_is_provided_by_initializer(): void
    {
        $kernel = $this->bootTempestKernel(
            'Test\\Ecotone\\Tempest\\Hardening\\Fixture\\InitializerService\\',
            configureContainer: function ($container): void {
                $container->addInitializer(GreetingServiceInitializer::class);
            },
        );

        $commandBus = $kernel->container->get(CommandBus::class);

        $this->assertSame('Hello real-boot', $commandBus->sendWithRouting('hardening.greet', 'real-boot'));
    }

    private function dispatchAndCaptureFailure(CommandBus $commandBus): Throwable
    {
        try {
            $commandBus->sendWithRouting('missing_reference.generate', 'monthly');
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('Dispatch was expected to fail');
    }

    private function bootTempestKernel(
        string $fixtureNamespace,
        ?callable $configureContainer = null,
    ): FrameworkKernel {
        $internalStorage = sys_get_temp_dir() . '/ecotone_hardening_real_boot_' . getmypid();

        $ecotoneLocation = new DiscoveryLocation('Ecotone\\Tempest\\', TempestTestPaths::srcPath());
        $fixtureLocation = new DiscoveryLocation(
            $fixtureNamespace,
            TempestTestPaths::packageRoot() . '/tests/Hardening/Fixture/' . basename(str_replace('\\', '/', rtrim($fixtureNamespace, '\\'))),
        );

        $kernel = new FrameworkKernel(
            root: TempestTestPaths::appRoot(),
            discoveryLocations: [$ecotoneLocation, $fixtureLocation],
            internalStorage: $internalStorage,
        );

        $kernel->registerKernel()
            ->validateRoot()
            ->loadEnv()
            ->registerEmergencyExceptionHandler()
            ->registerShutdownFunction()
            ->registerInternalStorage()
            ->loadComposer();

        $this->injectDiscoveryConfig($kernel, [$ecotoneLocation, $fixtureLocation]);

        $kernel->container->config(new EcotoneConfig(
            namespaces: [$fixtureNamespace],
            skippedModulePackageNames: ModulePackageList::allPackages(),
        ));

        if ($configureContainer !== null) {
            $configureContainer($kernel->container);
        }

        $kernel->loadConfig()
            ->bootDiscovery()
            ->registerExceptionHandler()
            ->event(KernelEvent::BOOTED);

        return $kernel;
    }

    private function injectDiscoveryConfig(FrameworkKernel $kernel, array $extraLocations): void
    {
        $vendorOnlyComposer = (new Composer(TempestTestPaths::appRoot()))->load();
        $vendorOnlyComposer->namespaces = [];

        $vendorLocations = (new AutoloadDiscoveryLocations(
            rootPath: TempestTestPaths::discoveryRoot(),
            composer: $vendorOnlyComposer,
        ))();

        $discoveryConfig = $kernel->container->get(DiscoveryConfig::class);
        $discoveryConfig->locations = [...$extraLocations, ...$vendorLocations];

        $kernel->container->config($discoveryConfig);
        $kernel->discoveryConfig = $discoveryConfig;
    }

    private function wipeCacheDirectory(): void
    {
        if (! is_dir($this->cacheDirectory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->cacheDirectory);
    }
}
