<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening;

use const DIRECTORY_SEPARATOR;

use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Test\Ecotone\Tempest\Hardening\Fixture\BootValidation\ExplodingFactoryInitializer;
use Throwable;

/**
 * Boot-time validation of external references: services that the compiled
 * messaging system references from Tempest's container must be checked at
 * boot as a capability probe — never by resolving them — so a missing
 * reference fails honestly at boot instead of poisoning channel wiring at
 * first dispatch ("no message handler registered").
 *
 * licence Apache-2.0
 * @internal
 */
final class BootValidationTest extends TestCase
{
    private string $cacheDirectory;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecotone_tempest';
        $this->wipeCacheDirectory();

        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();
        ExplodingFactoryInitializer::$invoked = false;
    }

    protected function tearDown(): void
    {
        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();

        $this->wipeCacheDirectory();
    }

    public function test_missing_handler_dependency_fails_at_boot_with_honest_error(): void
    {
        try {
            (new MessagingSystemInitializer())->initialize(
                $this->containerScanning('Test\\Ecotone\\Tempest\\Hardening\\Fixture\\BootValidation\\'),
            );

            $this->fail('Boot must fail: ReportGenerator references MissingServiceContract which nothing provides');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString(
                'MissingServiceContract',
                $exception->getMessage(),
                'The boot error must name the unresolvable reference',
            );
        }
    }

    public function test_dependency_registered_through_factory_passes_boot_without_invoking_the_factory(): void
    {
        $container = $this->containerScanning('Test\\Ecotone\\Tempest\\Hardening\\Fixture\\BootValidation\\');
        $container->addInitializer(ExplodingFactoryInitializer::class);

        (new MessagingSystemInitializer())->initialize($container);

        $this->assertFalse(
            ExplodingFactoryInitializer::$invoked,
            'Boot validation must accept a registered factory as proof of availability without invoking it',
        );
    }

    public function test_dependency_whose_own_dependency_does_not_exist_fails_at_boot(): void
    {
        try {
            (new MessagingSystemInitializer())->initialize(
                $this->containerScanning('Test\\Ecotone\\Tempest\\Hardening\\Fixture\\BootValidationChain\\'),
            );

            $this->fail('Boot must fail: RequiresMissingClass needs NonExistingCollaborator in its constructor');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString(
                'RequiresMissingClass',
                $exception->getMessage(),
                'The boot error must name the transitively unresolvable reference',
            );
        }
    }

    public function test_failing_boot_is_idempotent_and_reports_the_same_error_on_rerun(): void
    {
        $firstException = $this->bootAndCaptureFailure();

        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();

        $secondException = $this->bootAndCaptureFailure();

        $this->assertSame($firstException::class, $secondException::class);
        $this->assertSame(
            $firstException->getMessage(),
            $secondException->getMessage(),
            'A failed boot must not leave state behind that changes the error on the next attempt',
        );
    }

    private function bootAndCaptureFailure(): Throwable
    {
        try {
            (new MessagingSystemInitializer())->initialize(
                $this->containerScanning('Test\\Ecotone\\Tempest\\Hardening\\Fixture\\BootValidation\\'),
            );
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('Boot was expected to fail');
    }

    private function containerScanning(string $namespace): GenericContainer
    {
        $container = new GenericContainer();
        $container->config(new EcotoneConfig(
            namespaces: [$namespace],
            skippedModulePackageNames: ModulePackageList::allPackages(),
        ));

        return $container;
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
