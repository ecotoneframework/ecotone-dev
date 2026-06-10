<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config\PDO;

use Doctrine\DBAL\Driver\AbstractMySQLDriver;

/**
 * licence Apache-2.0
 */
final class MySqlDriver extends AbstractMySQLDriver
{
    public function connect(array $params): Connection
    {
        return new Connection($params['pdo']);
    }
}
