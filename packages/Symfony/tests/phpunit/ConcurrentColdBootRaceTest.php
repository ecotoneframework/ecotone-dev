<?php

declare(strict_types=1);

namespace Test;

use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\SymfonyBundle\App\Kernel;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class ConcurrentColdBootRaceTest extends TestCase
{
    private const COLD_CACHES_RACED_FOR = 15;
    private const PROCESSES_BOOTING_AT_ONCE = 8;
    private const DIED_BEFORE_REPORTING = 'died before reporting';

    public function test_processes_racing_to_boot_on_a_cold_cache_all_get_a_working_application(): void
    {
        $failures = [];
        for ($coldCache = 1; $coldCache <= self::COLD_CACHES_RACED_FOR; $coldCache++) {
            foreach ($this->bootInProcessesStartedTogether($this->uniquePath()) as $outcome) {
                if ($outcome !== 'handled') {
                    $failures[] = $outcome;
                }
            }
        }

        self::assertSame([], array_count_values($failures));
    }

    private function bootInProcessesStartedTogether(string $cacheDirectory): array
    {
        $startGate = $this->uniquePath() . '.gate';
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
                $this->bootOnceTheGateOpens($startGate, $cacheDirectory, $outcomeFile);
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

    private function bootOnceTheGateOpens(string $startGate, string $cacheDirectory, string $outcomeFile): never
    {
        register_shutdown_function(static function () use ($outcomeFile): void {
            $fatalError = error_get_last();
            if ($fatalError !== null && file_get_contents($outcomeFile) === self::DIED_BEFORE_REPORTING) {
                file_put_contents($outcomeFile, 'fatal error: ' . self::withoutCachePath($fatalError['message']));
            }
            posix_kill(posix_getpid(), SIGKILL);
        });

        try {
            flock(fopen($startGate, 'c'), LOCK_SH);
            $kernel = new KernelBootedOnAColdCache($cacheDirectory);
            $kernel->boot();
            $kernel->getContainer()->get(CommandBus::class)->sendWithRouting('setAmount', ['amount' => 100]);
            $outcome = $kernel->getContainer()->get(QueryBus::class)->sendWithRouting('getAmount') === 100 ? 'handled' : 'not handled';
        } catch (Throwable $failure) {
            $outcome = $failure::class . ': ' . self::withoutCachePath($failure->getMessage());
        }
        file_put_contents($outcomeFile, $outcome);

        exit(0);
    }

    private static function withoutCachePath(string $message): string
    {
        return substr(preg_replace('#/\S*ecotone_symfony_cold_boot_race/\S*#', '<cache>', $message), 0, 300);
    }

    private function uniquePath(): string
    {
        $directory = sys_get_temp_dir() . '/ecotone_symfony_cold_boot_race';
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        return $directory . '/' . uniqid('', true);
    }
}

/**
 * licence Apache-2.0
 */
final class KernelBootedOnAColdCache extends Kernel
{
    public function __construct(private string $coldCacheDirectory)
    {
        parent::__construct('test', false);
    }

    public function getCacheDir(): string
    {
        return $this->coldCacheDirectory;
    }
}
