<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Repository;

use DateTime;
use Ecotone\Tempest\TempestRepository;
use PHPUnit\Framework\TestCase;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;

/**
 * licence Apache-2.0
 * @internal
 */
final class TempestRepositoryTest extends TestCase
{
    public function test_it_does_not_support_non_models(): void
    {
        $repository = new TempestRepository();

        $this->assertFalse($repository->canHandle(DateTime::class));
    }

    public function test_it_does_support_tempest_database_models(): void
    {
        $repository = new TempestRepository();

        $modelClass = new class () {
            use IsDatabaseModel;

            public PrimaryKey $id;
        };

        $this->assertTrue($repository->canHandle($modelClass::class));
    }
}
