<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class ProjectingTestCase extends TestCase
{
    public static function getConnectionFactory(): DbalConnectionFactory
    {
        $dsn = getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone';
        return new DbalConnectionFactory($dsn);
    }
}
