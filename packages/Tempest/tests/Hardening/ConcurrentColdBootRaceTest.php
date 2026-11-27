<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening;

use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
#[RunTestsInSeparateProcesses]
final class ConcurrentColdBootRaceTest extends EcotoneIntegrationTestCase
{
    private const COLD_CACHES_RACED_FOR = 25;
    private const PROCESSES_BOOTING_AT_ONCE = 8;
    private const DIED_BEFORE_REPORTING = 'died before reporting';

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();
        $this->removeEcotoneCache();
    }

    public function test_processes_racing_to_boot_on_a_cold_cache_all_run_an_ecotone_console_command(): void
    {
        $failures = [];
        for ($coldCache = 1; $coldCache <= self::COLD_CACHES_RACED_FOR; $coldCache++) {
            $this->removeEcotoneCache();
            foreach ($this->bootInProcessesStartedTogether() as $outcome) {
                if ($outcome !== 'listed') {
                    $failures[] = $outcome;
                }
            }
        }

        self::assertSame([], array_count_values($failures));
    }

    private function bootInProcessesStartedTogether(): array
    {
        $startGate = sys_get_temp_dir() . '/ecotone_tempest_cold_boot_race_' . uniqid('', true) . '.gate';
        $closedGate = fopen($startGate, 'c');
        flock($closedGate, LOCK_EX);

        $outcomeFiles = [];
        $processes = [];
        for ($process = 1; $process <= self::PROCESSES_BOOTING_AT_ONCE; $process++) {
            $outcomeFile = $startGate . '.' . $process;
            file_put_contents($outcomeFile, self::DIED_BEFORE_REPORTING);
            $outcomeFiles[] = $outcomeFile;
            $processId = pcntl_fork();
            if ($processId === 0) {
                $this->bootOnceTheGateOpens($startGate, $outcomeFile);
            }
            $processes[] = $processId;
        }

        flock($closedGate, LOCK_UN);
        foreach ($processes as $processId) {
            pcntl_waitpid($processId, $status);
        }
        fclose($closedGate);
        unlink($startGate);

        return array_map(function (string $outcomeFile): string {
            $outcome = file_get_contents($outcomeFile);
            unlink($outcomeFile);

            return $outcome;
        }, $outcomeFiles);
    }

    private function bootOnceTheGateOpens(string $startGate, string $outcomeFile): never
    {
        register_shutdown_function(static function () use ($outcomeFile): void {
            $fatalError = error_get_last();
            if ($fatalError !== null && file_get_contents($outcomeFile) === self::DIED_BEFORE_REPORTING) {
                file_put_contents($outcomeFile, 'fatal error: ' . self::withoutHashes($fatalError['message']));
            }
            posix_kill(posix_getpid(), SIGKILL);
        });

        try {
            flock(fopen($startGate, 'c'), LOCK_SH);
            parent::setUp();
            $this->console
                ->call('ecotone:list')
                ->assertSuccess()
                ->assertContains('ecotone_test_queue');
            $outcome = 'listed';
        } catch (Throwable $failure) {
            $outcome = $failure::class . ': ' . self::withoutHashes($failure->getMessage());
        }
        file_put_contents($outcomeFile, $outcome);

        exit(0);
    }

    private static function withoutHashes(string $message): string
    {
        return substr(preg_replace('#[0-9a-f]{32,}#', '<hash>', $message), 0, 300);
    }

    private function removeEcotoneCache(): void
    {
        $cacheDirectory = sys_get_temp_dir() . '/ecotone_tempest';
        if (! is_dir($cacheDirectory)) {
            return;
        }

        $cached = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($cached as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
    }
}
