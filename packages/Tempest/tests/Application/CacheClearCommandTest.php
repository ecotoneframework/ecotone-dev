<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\MessagingSystemInitializer;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class CacheClearCommandTest extends EcotoneIntegrationTestCase
{
    protected function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            namespaces: ['Test\\Ecotone\\Tempest\\Fixture\\'],
            skippedModulePackageNames: ModulePackageList::allPackages(),
            test: true,
        );
    }

    public function test_cache_clear_command_removes_messaging_system_cache_file(): void
    {
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecotone_tempest';
        @mkdir($cacheDir, 0777, true);
        $messagingFile = $cacheDir . DIRECTORY_SEPARATOR . MessagingSystemInitializer::MESSAGING_SYSTEM_FILE_NAME;
        file_put_contents($messagingFile, 'test_cache_content');

        $this->assertTrue(file_exists($messagingFile));

        $this->console
            ->call('ecotone:cache:clear')
            ->assertSuccess();

        $this->assertFalse(file_exists($messagingFile));
    }

    public function test_cache_clear_command_removes_proxy_files(): void
    {
        $proxyDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecotone_tempest' . DIRECTORY_SEPARATOR . 'console_proxies';
        @mkdir($proxyDir, 0777, true);
        $proxyFile = $proxyDir . DIRECTORY_SEPARATOR . 'SomeProxy.php';
        file_put_contents($proxyFile, '<?php // proxy');

        $this->assertTrue(file_exists($proxyFile));

        $this->console
            ->call('ecotone:cache:clear')
            ->assertSuccess();

        $this->assertFalse(file_exists($proxyFile));
    }
}
