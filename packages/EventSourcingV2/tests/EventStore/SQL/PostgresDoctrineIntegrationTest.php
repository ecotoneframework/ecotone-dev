<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\EventStore\SQL;

use Test\Ecotone\EventSourcingV2\EventStore\SQL\Helpers\DatabaseConfig;

class PostgresDoctrineIntegrationTest extends SQLIntegrationTestCase
{
    protected static function config(): DatabaseConfig
    {
        return new DatabaseConfig('pg', 'doctrine');
    }
}