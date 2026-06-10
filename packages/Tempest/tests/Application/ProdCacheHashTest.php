<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class ProdCacheHashTest extends EcotoneIntegrationTestCase
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

    public function test_warm_prod_cache_path_returns_stable_non_null_config_hash(): void
    {
        $firstHash = MessagingSystemInitializer::getConfigHash();

        $this->assertNotNull(
            $firstHash,
            'Config hash must be non-null on first cold boot',
        );

        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();

        $this->setupKernel();

        $secondHash = MessagingSystemInitializer::getConfigHash();

        $this->assertSame(
            $firstHash,
            $secondHash,
            'Warm-cache boot must return the same config hash as the cold boot',
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
