<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\EventStore;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Test\Ecotone\EventSourcingV2\EventStore\Fixtures\PostgresTableProjector;

trait CatchupProjectionTransactionalTestTrait
{
    use CatchupProjectionTestCaseTrait;

    public function test_with_multiple_parallel_processes(): void
    {
        $config = $this->config()->bindConnectionString();
        $longRunningProcessInput = new InputStream();
        $longRunningProcess = new Process(['php', __DIR__ . '/console.php', 'long-running-append', '--dbConfig', $config->toString()]);
        $longRunningProcess->setInput($longRunningProcessInput);
        $initProcess = new Process(['php', __DIR__ . '/console.php', 'init', '--dbConfig', $config->toString()]);
        $catchupProcess = new Process(['php', __DIR__ . '/console.php', 'catchup-projection', '--dbConfig', $config->toString()]);

        $connection = $this->config()->getConnection();
        $counterBaseProjection = new PostgresTableProjector($connection, 'test_event_base');
        $counterCatchupProjection = new PostgresTableProjector($connection, 'test_event_catchup');

        $initProcess->run();
        self::assertTrue($initProcess->isSuccessful(), "Init process failed: " . $initProcess->getOutput() . $initProcess->getErrorOutput());
        self::assertEmpty($counterCatchupProjection->getState());

        $longRunningProcess->start();
        $longRunningProcess->waitUntil(function ($type, $output) {
            return $output === "Events appended, waiting some input to commit\n";
        });

        $catchupProcess->start();

        $maxSleep = 1000;
        while ($counterBaseProjection->getState() != $counterCatchupProjection->getState()) {
            usleep(1000);
            $maxSleep--;
            if ($maxSleep === 0) {
                self::fail('Projection did not catch up');
            }
        }

        $longRunningProcessInput->write("trigger commit\n");
        $longRunningProcessInput->close();

        $longRunningProcess->wait();
        $catchupProcess->wait();

        $realEventIdsStatement = $connection->prepare('SELECT id FROM es_event ORDER BY id');
        $realEventIdsStatement->execute();
        $realEventIds = [];
        while ($row = $realEventIdsStatement->fetch()) {
            $realEventIds[] = (int) $row['id'];
        }

        self::assertTrue($longRunningProcess->isSuccessful());
        self::assertTrue($catchupProcess->isSuccessful(), $catchupProcess->getOutput());
        self::assertEquals($realEventIds, $counterBaseProjection->getState(), "Base projection did not catch up");
        self::assertEquals($realEventIds, $counterCatchupProjection->getState(), "Catchup projection did not catch up");
    }

}