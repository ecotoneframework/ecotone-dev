<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Kernel;
use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
final class ConcurrentWritersTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    private const REFRESHES = 40;
    private const CONSUMING_MILLISECONDS = 40_000;

    /**
     * @var list<int>
     */
    private array $processes = [];

    public function test_three_concurrent_processes_writing_the_internal_database_never_fail_with_database_is_locked(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $report = sys_get_temp_dir() . '/pulse-writers-' . uniqid() . '.txt';
        foreach (['orders', 'billing'] as $service) {
            $this->inAnotherProcess(fn () => $this->realService($service)->run($this->serviceChannels[$service], ExecutionPollingMetadata::createWithDefaults()->withExecutionTimeLimitInMilliseconds(self::CONSUMING_MILLISECONDS)));
        }
        $this->inAnotherProcess(fn () => (new CommandTester((new Application($this->bootedKernel()))->find('ecotone:run')))->execute(['consumerName' => $this->pulseChannel, '--executionTimeLimit' => self::CONSUMING_MILLISECONDS]));
        $writer = $this->inAnotherProcess(function () use ($report): void {
            $kernel = $this->bootedKernel();
            $statuses = [];
            for ($refresh = 0; $refresh < self::REFRESHES; $refresh++) {
                $statuses[] = $kernel->handle(Request::create('/refresh', 'POST'))->getStatusCode();
            }
            file_put_contents($report, implode(',', $statuses));
        });

        $statuses = [];
        while (count($statuses) < self::REFRESHES || pcntl_waitpid($writer, $status, WNOHANG) === 0) {
            $client->request('POST', '/refresh');
            $statuses[] = $client->getResponse()->getStatusCode();
        }

        self::assertSame(array_fill(0, count($statuses), 302), $statuses);
        self::assertSame(implode(',', array_fill(0, self::REFRESHES, 302)), file_get_contents($report));
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            posix_kill($process, SIGKILL);
            pcntl_waitpid($process, $status);
        }
        $this->processes = [];
        parent::tearDown();
    }

    private function inAnotherProcess(callable $work): int
    {
        $process = pcntl_fork();
        if ($process !== 0) {
            $this->processes[] = $process;

            return $process;
        }

        try {
            $work();
        } finally {
            posix_kill(posix_getpid(), SIGKILL);
        }
    }

    private function bootedKernel(): Kernel
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();

        return $kernel;
    }
}
