<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening;

use const DIRECTORY_SEPARATOR;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Throwable;

/**
 * Runtime failures for services the compiled messaging system references from
 * Tempest's container. Boot stays lazy — a missing reference must NOT block
 * boot; it must fail at dispatch with an error naming the missing service,
 * and dispatching again must report the very same error instead of a
 * misleading follow-up ("no message handler registered") caused by half-built
 * channel wiring left behind by the first failure.
 *
 * licence Apache-2.0
 * @internal
 */
final class MissingReferenceRuntimeFailureTest extends TestCase
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
        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();

        $this->wipeCacheDirectory();
    }

    public function test_missing_handler_dependency_boots_but_fails_at_dispatch_with_honest_error(): void
    {
        $messagingSystem = $this->bootMessagingSystem('Test\\Ecotone\\Tempest\\Hardening\\Fixture\\MissingReference\\');

        try {
            $messagingSystem->getCommandBus()->sendWithRouting('missing_reference.generate', 'monthly');

            $this->fail('Dispatch must fail: ReportGenerator references MissingServiceContract which nothing provides');
        } catch (Throwable $exception) {
            $this->assertStringContainsString(
                'MissingServiceContract',
                $exception->getMessage(),
                'The dispatch error must name the unresolvable reference',
            );
        }
    }

    public function test_dependency_whose_own_dependency_does_not_exist_fails_at_dispatch(): void
    {
        $messagingSystem = $this->bootMessagingSystem('Test\\Ecotone\\Tempest\\Hardening\\Fixture\\MissingReferenceChain\\');

        try {
            $messagingSystem->getCommandBus()->sendWithRouting('missing_reference.chain', 'payload');

            $this->fail('Dispatch must fail: RequiresMissingClass needs NonExistingCollaborator in its constructor');
        } catch (Throwable $exception) {
            $this->assertStringContainsString(
                'NonExistingCollaborator',
                $exception->getMessage(),
                'The dispatch error must name what could not be resolved',
            );
        }
    }

    public function test_failed_dispatch_reports_the_same_error_when_repeated(): void
    {
        $messagingSystem = $this->bootMessagingSystem('Test\\Ecotone\\Tempest\\Hardening\\Fixture\\MissingReference\\');

        $firstException = $this->dispatchAndCaptureFailure($messagingSystem);
        $secondException = $this->dispatchAndCaptureFailure($messagingSystem);

        $this->assertSame($firstException::class, $secondException::class);
        $this->assertSame(
            $firstException->getMessage(),
            $secondException->getMessage(),
            'A failed dispatch must not leave half-built services behind that change the error on the next attempt',
        );
    }

    private function dispatchAndCaptureFailure(ConfiguredMessagingSystem $messagingSystem): Throwable
    {
        try {
            $messagingSystem->getCommandBus()->sendWithRouting('missing_reference.generate', 'monthly');
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('Dispatch was expected to fail');
    }

    private function bootMessagingSystem(string $namespace): ConfiguredMessagingSystem
    {
        $container = new GenericContainer();
        $container->config(new EcotoneConfig(
            namespaces: [$namespace],
            skippedModulePackageNames: ModulePackageList::allPackages(),
        ));

        return (new MessagingSystemInitializer())->initialize($container);
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
