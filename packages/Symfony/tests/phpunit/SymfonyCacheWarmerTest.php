<?php

namespace Test;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

class SymfonyCacheWarmerTest extends KernelTestCase
{
    private const APP_ENV = 'test_proxy_warmup';
    private const WITH_WARMUP = false;
    private const WITHOUT_WARMUP = true;
    private static string $projectDir;
    private static string $cacheDir;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $kernel = self::bootKernel();
        self::$projectDir = $kernel->getProjectDir();
        self::$cacheDir = $kernel->getCacheDir().'/../'.self::APP_ENV;
        self::ensureKernelShutdown();
        self::$kernel = null;
    }

    public function test_cache_clear(): void
    {
        self::executeCacheClearInSeparateProcess(self::WITHOUT_WARMUP);

        self::assertCount(0, \glob(self::$cacheDir . '/ecotone/*', GLOB_MARK));
    }

    public function test_cache_warmup(): void
    {
        self::executeCacheClearInSeparateProcess(self::WITH_WARMUP);

        self::assertDirectoryExists(self::$cacheDir . '/ecotone');

        self::assertCount(self::gatewayCount(), \glob(self::$cacheDir . '/ecotone/*', GLOB_MARK));
        self::ensureKernelShutdown();
    }

    private function executeCacheClearInSeparateProcess(bool $withoutWarmup): void
    {
        $arguments = ['php', 'bin/console', 'cache:clear'];
        if ($withoutWarmup) {
            $arguments[] = '--no-warmup';
        }
        $process = new Process($arguments, self::$projectDir, ['APP_ENV' => self::APP_ENV]);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    private static function gatewayCount(): int
    {
        /** @var ConfiguredMessagingSystem $messagingSystem */
        $messagingSystem = self::bootKernel()->getContainer()->get(ConfiguredMessagingSystem::class);

        return count($messagingSystem->getGatewayList());
    }
}