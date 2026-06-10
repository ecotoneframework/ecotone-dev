<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config\PDO;

use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;

/**
 * A Doctrine driver whose connect() returns TempestDynamicDriverConnection.
 * Combined with closing the Doctrine Connection on tenant switch, this makes the
 * DbalTransactionInterceptor re-resolve the current Tempest Connection singleton
 * on every message so transactions follow the active tenant.
 *
 * licence Apache-2.0
 */
final class TempestDynamicDriver extends AbstractPostgreSQLDriver
{
    public function connect(array $params): DriverConnection
    {
        return new TempestDynamicDriverConnection();
    }
}
