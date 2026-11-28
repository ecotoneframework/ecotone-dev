<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\InternalDatabase;
use App\Storage\InternalDatabaseConnectionFactory;
use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use PDO;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
final class WriteLockTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    public function test_a_transaction_on_the_internal_database_holds_the_write_lock_from_its_first_statement(): void
    {
        $this->configurePulseWithServices('orders');
        $kernel = self::bootKernel();
        (new CommandTester((new Application($kernel))->find('pulse:setup')))->execute([]);
        $database = self::getContainer()->get(InternalDatabase::class);
        $anotherProcess = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $_SERVER['PULSE_INTERNAL_DATABASE_PATH'], 'driverOptions' => [PDO::ATTR_TIMEOUT => 0]]);

        $database->connection()->beginTransaction();
        $database->connection()->fetchOne('SELECT COUNT(*) FROM pulse_heartbeat');
        try {
            $anotherProcess->executeStatement("INSERT INTO pulse_heartbeat (process, beat_at) VALUES ('other', 1)");
            $refused = false;
        } catch (DriverException) {
            $refused = true;
        }
        $database->connection()->executeStatement("INSERT INTO pulse_heartbeat (process, beat_at) VALUES ('mine', 1)");
        $database->connection()->commit();
        $anotherProcess->close();

        self::assertTrue($refused);
    }

    public function test_a_transaction_refused_the_write_lock_twice_leaves_the_next_one_committed_and_the_lock_free(): void
    {
        $this->configurePulseWithServices('orders');
        (new CommandTester((new Application(self::bootKernel()))->find('pulse:setup')))->execute([]);
        $database = new InternalDatabase(new InternalDatabaseConnectionFactory($_SERVER['PULSE_INTERNAL_DATABASE_PATH'], 0));
        $anotherProcess = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $_SERVER['PULSE_INTERNAL_DATABASE_PATH'], 'driverOptions' => [PDO::ATTR_TIMEOUT => 0]]);

        $refusals = 0;
        foreach (['first', 'second'] as $attempt) {
            $anotherProcess->executeStatement('BEGIN IMMEDIATE');
            try {
                $database->inTransaction(static fn ($connection) => $connection->executeStatement('INSERT INTO pulse_heartbeat (process, beat_at) VALUES (?, 1)', [$attempt]));
            } catch (LockWaitTimeoutException) {
                $refusals++;
            }
            $anotherProcess->executeStatement('ROLLBACK');
        }
        $database->inTransaction(static fn ($connection) => $connection->executeStatement("INSERT INTO pulse_heartbeat (process, beat_at) VALUES ('mine', 1)"));
        $anotherProcess->executeStatement("INSERT INTO pulse_heartbeat (process, beat_at) VALUES ('other', 1)");
        $processes = $anotherProcess->fetchFirstColumn('SELECT process FROM pulse_heartbeat ORDER BY process');
        $database->connection()->close();
        $anotherProcess->close();

        self::assertSame(2, $refusals);
        self::assertSame(['mine', 'other'], $processes);
    }
}
