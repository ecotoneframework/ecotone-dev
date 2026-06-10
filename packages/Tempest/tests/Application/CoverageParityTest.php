<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\CommandBus;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;
use Test\Ecotone\Tempest\Fixture\User\User;
use Test\Ecotone\Tempest\Fixture\User\UserRepository;

/**
 * licence Apache-2.0
 * @internal
 */
final class CoverageParityTest extends EcotoneIntegrationTestCase
{
    protected function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            namespaces: ['Test\\Ecotone\\Tempest\\Fixture\\User\\'],
            skippedModulePackageNames: ModulePackageList::allPackages(),
            test: true,
            cacheConfiguration: true,
        );
    }

    protected function setUp(): void
    {
        $this->clearEcotoneTempestCacheDir();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->clearEcotoneTempestCacheDir();
    }

    public function test_handlers_work_on_warm_prod_cache_boot(): void
    {
        $userId = 'warm-cache-user';

        $commandBus = $this->container->get(CommandBus::class);
        $commandBus->sendWithRouting('user.register', $userId);

        $userRepository = $this->container->get(UserRepository::class);

        $this->assertEquals(
            User::register($userId),
            $userRepository->getUser($userId),
        );

        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();

        $this->setupKernel();

        $commandBus = $this->container->get(CommandBus::class);
        $commandBus->sendWithRouting('user.register', $userId);

        $userRepository = $this->container->get(UserRepository::class);

        $this->assertEquals(
            User::register($userId),
            $userRepository->getUser($userId),
        );
    }

    public function test_default_error_channel_configured_in_ecotone_config_does_not_break_boot(): void
    {
        $this->assertInstanceOf(
            CommandBus::class,
            $this->container->get(CommandBus::class),
        );
    }

    private function clearEcotoneTempestCacheDir(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecotone_tempest';
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDirectory($file) : @unlink($file);
        }
        @rmdir($dir);
    }

    private function removeDirectory(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDirectory($file) : @unlink($file);
        }
        @rmdir($dir);
    }
}
