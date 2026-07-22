<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening;

use const DIRECTORY_SEPARATOR;

use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\CommandBus;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
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
 * Boot validation exercised through a REAL Tempest kernel boot (the RealBootTest
 * harness): registerKernel -> loadComposer -> discovery -> BOOTED. During
 * bootDiscovery, EcotoneConsoleCommandDiscovery compiles the messaging system —
 * so a missing external reference must fail THE KERNEL BOOT itself, with the
 * honest aggregate error, and booting again must report the same error.
 *
 * licence Apache-2.0
 * @internal
 */
final class RealBootValidationTest extends TestCase
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

    public function test_missing_handler_dependency_fails_the_tempest_kernel_boot(): void
    {
        try {
            $this->bootTempestKernel(
                'Test\\Ecotone\\Tempest\\Hardening\\Fixture\\BootValidation\\',
            );

            $this->fail('The kernel boot must fail: ReportGenerator references MissingServiceContract which nothing provides');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString('MissingServiceContract', $exception->getMessage());
        }
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

    public function test_failed_kernel_boot_is_idempotent_and_reports_the_same_error(): void
    {
        $firstException = $this->bootAndCaptureFailure();

        restore_exception_handler();
        restore_error_handler();
        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();

        $secondException = $this->bootAndCaptureFailure();

        $this->assertSame($firstException::class, $secondException::class);
        $this->assertSame(
            $firstException->getMessage(),
            $secondException->getMessage(),
            'A failed kernel boot must not leave state behind that changes the error on the next boot',
        );
    }

    public function test_kernel_boots_despite_missing_dependency_in_test_mode(): void
    {
        $kernel = $this->bootTempestKernel(
            'Test\\Ecotone\\Tempest\\Hardening\\Fixture\\BootValidation\\',
            testMode: true,
        );

        $this->assertNotNull(
            $kernel->container->get(CommandBus::class),
            'Test-mode setups boot intentionally partial configurations - validation must not apply to them; this also proves the boot failure in the other tests comes from validation',
        );
    }

    private function bootAndCaptureFailure(): Throwable
    {
        try {
            $this->bootTempestKernel('Test\\Ecotone\\Tempest\\Hardening\\Fixture\\BootValidation\\');
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('The kernel boot was expected to fail');
    }

    private function bootTempestKernel(
        string $fixtureNamespace,
        ?callable $configureContainer = null,
        bool $testMode = false,
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
            test: $testMode,
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
