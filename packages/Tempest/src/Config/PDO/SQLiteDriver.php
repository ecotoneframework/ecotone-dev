<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config\PDO;

use Doctrine\DBAL\Driver\AbstractSQLiteDriver;

/**
 * licence Apache-2.0
 */
final class SQLiteDriver extends AbstractSQLiteDriver
{
    public function connect(array $params): Connection
    {
        return new Connection($params['pdo']);
    }
}
