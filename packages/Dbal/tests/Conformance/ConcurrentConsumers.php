<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use RuntimeException;

/**
 * licence Apache-2.0
 * @internal
 */
trait ConcurrentConsumers
{
    private function runConcurrentConsumers(int $numberOfConsumers, string $testFile, string $consumerMethod): void
    {
        $children = [];
        $packageAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $autoload = is_file($packageAutoload) ? $packageAutoload : dirname(__DIR__, 4) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . '; require ' . var_export($testFile, true) . '; ' . self::class . '::' . $consumerMethod . '();';
        for ($part = 0; $part < $numberOfConsumers; $part++) {
            $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $children[] = [$process, $pipes];
        }
        try {
            $ready = [];
            foreach ($children as [$process, $pipes]) {
                $ready[] = fgets($pipes[1]);
            }
            foreach ($children as $number => [$process, $pipes]) {
                if ($ready[$number] === "ready\n") {
                    fwrite($pipes[0], "consume\n");
                }
                fclose($pipes[0]);
            }
            self::assertSame(array_fill(0, $numberOfConsumers, "ready\n"), $ready);
            foreach ($children as [$process, $pipes]) {
                self::assertSame('', stream_get_contents($pipes[1]));
                self::assertSame('', stream_get_contents($pipes[2]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process));
            }
        } finally {
            foreach ($children as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }

    }

    private static function concurrentConsumerBarrier(): object
    {
        return new class () {
            private bool $released = false;

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 101, pointcut: AsynchronousRunningEndpoint::class)]
            public function awaitOtherConsumer(MethodInvocation $invocation): mixed
            {
                if (! $this->released) {
                    fwrite(STDOUT, "ready\n");
                    if (fgets(STDIN) !== "consume\n") {
                        throw new RuntimeException('Concurrent consumer start was not released.');
                    }
                    $this->released = true;
                }

                return $invocation->proceed();
            }
        };
    }
}
