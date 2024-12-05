<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\EventStore\SQL;

use Test\Ecotone\EventSourcingV2\EventStore\SQL\Helpers\DatabaseConfig;

class MysqlDoctrineIntegrationTest extends SQLIntegrationTestCase
{
    protected static function config(): DatabaseConfig
    {
        return new DatabaseConfig('mysql', 'doctrine');
    }
}