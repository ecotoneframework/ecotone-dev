<?php

namespace Test;

use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class CacheClearingTest extends KernelTestCase
{
    private string $symfonyEcotoneCacheDir;
    private string $ecotoneLiteCacheDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel([
            'environment' => 'test',
        ]);

        $this->filesystem = new Filesystem();

        // Get the actual cache directories
        /** @var ServiceCacheConfiguration $cacheConfig */
        $cacheConfig = self::getContainer()->get(ServiceCacheConfiguration::REFERENCE_NAME);
        $this->symfonyEcotoneCacheDir = $cacheConfig->getPath();
        $this->ecotoneLiteCacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecotone';
    }

    public function test_cache_clear_command_triggers_ecotone_cache_clearing(): void
    {
        // Create cache directories and test files
        $this->filesystem->mkdir($this->symfonyEcotoneCacheDir);
        $this->filesystem->dumpFile($this->symfonyEcotoneCacheDir . '/test_symfony_cache.txt', 'Symfony Ecotone cache content');

        $this->filesystem->mkdir($this->ecotoneLiteCacheDir);
        $this->filesystem->dumpFile($this->ecotoneLiteCacheDir . '/test_lite_cache.txt', 'EcotoneLite cache content');

        // Verify files exist
        $this->assertTrue($this->filesystem->exists($this->symfonyEcotoneCacheDir . '/test_symfony_cache.txt'));
        $this->assertTrue($this->filesystem->exists($this->ecotoneLiteCacheDir . '/test_lite_cache.txt'));

        // Run cache:clear command with --no-warmup to avoid cache regeneration issues
        $application = new Application(self::$kernel);
        $command = $application->find('cache:clear');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--no-warmup' => true]);

        // Verify both Ecotone cache files were removed by the cache:clear command
        $this->assertFalse($this->filesystem->exists($this->symfonyEcotoneCacheDir . '/test_symfony_cache.txt'));
        $this->assertFalse($this->filesystem->exists($this->ecotoneLiteCacheDir . '/test_lite_cache.txt'));

        // Verify command executed successfully
        $this->assertEquals(0, $commandTester->getStatusCode());
    }
}
