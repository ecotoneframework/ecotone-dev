<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening;

use const DIRECTORY_SEPARATOR;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Test\Ecotone\Tempest\Hardening\Fixture\InitializerService\GreetingServiceInitializer;

/**
 * End-to-end Part A regression through the COMPILED container path (the one
 * EcotoneLite never exercises): a handler referencing a Tempest
 * initializer-provided service must boot, pass validation, and execute — both
 * on a cold compile and on a warm production-cache boot. Under the pre-fix
 * adapter this failed with "Reference ... was not found in definitions" and
 * then retry-looped on a poisoned channel with "no message handler registered".
 *
 * licence Apache-2.0
 * @internal
 */
final class CompiledContainerInitializerServiceTest extends TestCase
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

    public function test_handler_using_initializer_provided_service_works_through_compiled_container(): void
    {
        $messagingSystem = (new MessagingSystemInitializer())->initialize($this->containerWithInitializer());

        $this->assertSame(
            'Hello world',
            $messagingSystem->getCommandBus()->sendWithRouting('hardening.greet', 'world'),
        );
    }

    public function test_handler_using_initializer_provided_service_works_on_warm_production_cache_boot(): void
    {
        (new MessagingSystemInitializer())->initialize($this->containerWithInitializer())
            ->getCommandBus()->sendWithRouting('hardening.greet', 'first');

        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();

        $warmBootSystem = (new MessagingSystemInitializer())->initialize($this->containerWithInitializer());

        $this->assertSame(
            'Hello warm',
            $warmBootSystem->getCommandBus()->sendWithRouting('hardening.greet', 'warm'),
        );
    }

    private function containerWithInitializer(): GenericContainer
    {
        $container = new GenericContainer();
        $container->config(new EcotoneConfig(
            namespaces: ['Test\\Ecotone\\Tempest\\Hardening\\Fixture\\InitializerService\\'],
            skippedModulePackageNames: ModulePackageList::allPackages(),
        ));
        $container->addInitializer(GreetingServiceInitializer::class);

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
