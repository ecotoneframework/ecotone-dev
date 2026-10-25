<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;

trait ConcurrentRespondersTrait
{
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
        $process = pcntl_fork();
        if ($process !== 0) {
            $this->responderProcesses[] = $process;

            return;
        }

        try {
            sleep($startAfterSeconds);
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
