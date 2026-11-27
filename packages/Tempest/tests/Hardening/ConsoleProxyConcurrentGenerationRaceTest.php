<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening;

use Ecotone\Messaging\Config\ConsoleCommandConfiguration;
use Ecotone\Tempest\ConsoleCommandProxyGenerator;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class ConsoleProxyConcurrentGenerationRaceTest extends TestCase
{
    private const EMPTY_DIRECTORIES_RACED_FOR = 30;
    private const PROCESSES_GENERATING_AT_ONCE = 8;
    private const COMMANDS = 40;
    private const DIED_BEFORE_REPORTING = 'died before reporting';

    public function test_processes_racing_to_generate_console_proxies_all_load_every_proxy_class(): void
    {
        $failures = [];
        for ($emptyDirectory = 1; $emptyDirectory <= self::EMPTY_DIRECTORIES_RACED_FOR; $emptyDirectory++) {
            foreach ($this->generateInProcessesStartedTogether($this->uniquePath() . '/proxies') as $outcome) {
                if ($outcome !== 'loaded') {
                    $failures[] = $outcome;
                }
            }
        }

        self::assertSame([], array_count_values($failures));
    }

    /**
     * @return string[]
     */
    private function generateInProcessesStartedTogether(string $proxyDirectory): array
    {
        $startGate = $this->uniquePath() . '.gate';
        $closedGate = fopen($startGate, 'c');
        flock($closedGate, LOCK_EX);

        $outcomeFiles = [];
        $processes = [];
        for ($process = 1; $process <= self::PROCESSES_GENERATING_AT_ONCE; $process++) {
            $outcomeFile = $startGate . '.' . $process;
            file_put_contents($outcomeFile, self::DIED_BEFORE_REPORTING);
            $outcomeFiles[] = $outcomeFile;
            $processId = pcntl_fork();
            if ($processId === 0) {
                $this->generateOnceTheGateOpens($startGate, $proxyDirectory, $outcomeFile);
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

    private function generateOnceTheGateOpens(string $startGate, string $proxyDirectory, string $outcomeFile): never
    {
        register_shutdown_function(static function () use ($outcomeFile): void {
            $fatalError = error_get_last();
            if ($fatalError !== null && file_get_contents($outcomeFile) === self::DIED_BEFORE_REPORTING) {
                file_put_contents($outcomeFile, 'fatal error: ' . self::withoutProxyPath($fatalError['message']));
            }
            posix_kill(posix_getpid(), SIGKILL);
        });

        try {
            flock(fopen($startGate, 'c'), LOCK_SH);
            $commands = [];
            for ($command = 1; $command <= self::COMMANDS; $command++) {
                $commands[] = ConsoleCommandConfiguration::create("raced.channel.{$command}", "raced:command{$command}", []);
            }
            foreach ((new ConsoleCommandProxyGenerator())->generate($commands, $proxyDirectory, 'configuration-hash') as $proxyFile) {
                require_once $proxyFile;
            }
            $outcome = 'loaded';
            for ($command = 1; $command <= self::COMMANDS; $command++) {
                if (! class_exists("Ecotone\\Tempest\\Generated\\EcotoneConsoleCommand_raced_command{$command}", false)) {
                    $outcome = 'a generated proxy file held no proxy class';
                }
            }
        } catch (Throwable $failure) {
            $outcome = $failure::class . ': ' . self::withoutProxyPath($failure->getMessage());
        }
        file_put_contents($outcomeFile, $outcome);

        exit(0);
    }

    private static function withoutProxyPath(string $message): string
    {
        return preg_replace('#/\S*ecotone_console_proxy_race/\S*#', '<proxies>', $message);
    }

    private function uniquePath(): string
    {
        $directory = sys_get_temp_dir() . '/ecotone_console_proxy_race';
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        return $directory . '/' . uniqid('', true);
    }
}
