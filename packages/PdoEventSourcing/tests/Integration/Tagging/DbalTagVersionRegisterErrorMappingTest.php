<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Ecotone\EventSourcing\Dbal\Tag\DbalTagVersionRegister;
use Ecotone\Messaging\Support\ConcurrencyException;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class DbalTagVersionRegisterErrorMappingTest extends TestCase
{
    public function test_mariadb_1020_and_postgres_lock_not_available_map_to_concurrency_exception(): void
    {
        $register = new DbalTagVersionRegister();

        $mariaDbSnapshotHazard = new class ('Record has changed since last read', 1020) extends Exception implements DriverExceptionInterface {
            public function getSQLState(): ?string
            {
                return null;
            }
        };

        $this->expectException(ConcurrencyException::class);

        $register->runGuarded(static function () use ($mariaDbSnapshotHazard): void {
            throw $mariaDbSnapshotHazard;
        });
    }

    public function test_postgres_lock_not_available_maps_to_concurrency_exception(): void
    {
        $register = new DbalTagVersionRegister();

        $postgresLockNotAvailable = new class ('canceling statement due to lock timeout', 0) extends Exception implements DriverExceptionInterface {
            public function getSQLState(): ?string
            {
                return '55P03';
            }
        };

        $this->expectException(ConcurrencyException::class);

        $register->runGuarded(static function () use ($postgresLockNotAvailable): void {
            throw $postgresLockNotAvailable;
        });
    }

    public function test_an_unrelated_driver_exception_is_not_mapped_to_concurrency_exception(): void
    {
        $register = new DbalTagVersionRegister();

        $unrelated = new class ('syntax error', 42000) extends Exception implements DriverExceptionInterface {
            public function getSQLState(): ?string
            {
                return '42000';
            }
        };

        $this->expectException($unrelated::class);

        $register->runGuarded(static function () use ($unrelated): void {
            throw $unrelated;
        });
    }
}