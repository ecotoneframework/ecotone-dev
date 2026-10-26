<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use RuntimeException;
use Throwable;

trait ConcurrentRespondersTrait
{
    private const RESPONDER_STARTED = 'started';

    /**
     * @var list<int>
     */
    private array $responderProcesses = [];

    private function awaitRepliesFor(int $seconds): void
    {
        $_SERVER['PULSE_AWAIT_TIMEOUT'] = $_ENV['PULSE_AWAIT_TIMEOUT'] = (string) $seconds;
        putenv('PULSE_AWAIT_TIMEOUT=' . $seconds);
    }

    /**
     * @param list<string> $services
     * @param (callable(): array<string, \Ecotone\Api\Lite\Test\FlowTestSupport>)|null $build
     */
    private function respondInBackground(array $services, ?callable $build = null, int $startAfterSeconds = 0): void
    {
        $readiness = $startAfterSeconds === 0 ? stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP) : null;
        $process = pcntl_fork();
        if ($process !== 0) {
            $this->responderProcesses[] = $process;
            if ($readiness !== null) {
                fclose($readiness[1]);
                $this->awaitResponderStarted($readiness[0], $services);
            }

            return;
        }

        try {
            if ($readiness !== null) {
                fclose($readiness[0]);
            }
            sleep($startAfterSeconds);
            try {
                $kernel = new Kernel('test', true);
                $kernel->boot();
                $applications = [];
                if ($build !== null) {
                    $applications = $build();
                } else {
                    foreach ($services as $service) {
                        $applications[$service] = $this->realService($service);
                    }
                }
            } catch (Throwable $failure) {
                if ($readiness !== null) {
                    fwrite($readiness[1], $failure::class . ': ' . $failure->getMessage());
                }

                throw $failure;
            }
            if ($readiness !== null) {
                fwrite($readiness[1], self::RESPONDER_STARTED);
                fclose($readiness[1]);
            }
            $deadline = microtime(true) + 40;
            while (microtime(true) < $deadline) {
                foreach ($applications as $service => $application) {
                    $this->runService($application, $service);
                }
                $this->runPulseWorker($kernel);
                usleep(100_000);
            }
        } finally {
            posix_kill(posix_getpid(), SIGKILL);
        }
    }

    /**
     * @param list<string> $services
     */
    private function awaitResponderStarted(mixed $readiness, array $services): void
    {
        stream_set_timeout($readiness, 60);
        $answer = stream_get_contents($readiness);
        fclose($readiness);
        if ($answer !== self::RESPONDER_STARTED) {
            throw new RuntimeException(sprintf(
                'The background responder for %s did not start, so the test would wait for a service that never answers: %s',
                implode(', ', $services),
                $answer === '' || $answer === false ? 'it exited or timed out before its services were built.' : $answer,
            ));
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->responderProcesses as $process) {
            posix_kill($process, SIGKILL);
            pcntl_waitpid($process, $status);
        }
        $this->responderProcesses = [];
        parent::tearDown();
    }
}
