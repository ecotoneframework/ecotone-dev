<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Kernel;
use App\Tests\Support\ConcurrentRespondersTrait;
use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
final class ConcurrentWritersTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;
    use ConcurrentRespondersTrait;

    private const REFRESHES = 40;

    public function test_three_processes_writing_the_internal_database_never_fail_with_database_is_locked(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $report = sys_get_temp_dir() . '/pulse-writers-' . uniqid() . '.txt';
        $this->respondInBackground(['orders', 'billing']);
        $writer = pcntl_fork();
        if ($writer === 0) {
            $statuses = [];
            $kernel = new Kernel('test', true);
            $kernel->boot();
            for ($refresh = 0; $refresh < self::REFRESHES; $refresh++) {
                $statuses[] = $kernel->handle(Request::create('/refresh', 'POST'))->getStatusCode();
            }
            file_put_contents($report, implode(',', $statuses));
            posix_kill(posix_getpid(), SIGKILL);
        }

        $statuses = [];
        for ($refresh = 0; $refresh < self::REFRESHES; $refresh++) {
            $client->request('POST', '/refresh');
            $statuses[] = $client->getResponse()->getStatusCode();
        }
        pcntl_waitpid($writer, $status);

        self::assertSame(array_fill(0, self::REFRESHES, 302), $statuses);
        self::assertSame(implode(',', array_fill(0, self::REFRESHES, 302)), file_get_contents($report));
    }
}
