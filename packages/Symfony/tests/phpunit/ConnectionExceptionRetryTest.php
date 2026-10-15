<?php

declare(strict_types=1);

namespace Test;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConnectionExceptionRetryTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel([
            'environment' => 'test',
        ]);
    }

    protected function tearDown(): void
    {
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_consumer_reconnects_as_many_times_as_max_attempts_in_default_connection_exception_retry(): void
    {
        $this->assertSame(0, $this->runConsumer('connection_lost_four_times'));
    }

    public function test_consumer_gives_up_once_max_attempts_in_default_connection_exception_retry_are_used(): void
    {
        $this->assertNotSame(0, $this->runConsumer('connection_lost_five_times'));
    }

    private function runConsumer(string $consumerName): int
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        return $application->run(
            new ArrayInput(['command' => 'ecotone:run', 'consumerName' => $consumerName, '--executionTimeLimit' => 500]),
            new BufferedOutput(),
        );
    }
}
