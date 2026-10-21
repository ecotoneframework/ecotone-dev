<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\InternalDatabase;
use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
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
}
