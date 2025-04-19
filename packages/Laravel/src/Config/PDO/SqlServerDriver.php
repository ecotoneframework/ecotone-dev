<?php

namespace Ecotone\Laravel\Config\PDO;

use Doctrine\DBAL\Driver\AbstractSQLServerDriver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;

/**
 * licence MIT
 */
class SqlServerDriver extends AbstractSQLServerDriver
{
    /**
     * Create a new database connection.
     *
     * @param  mixed[]  $params
     * @param  string|null  $username
     * @param  string|null  $password
     * @param  mixed[]  $driverOptions
     * @return SqlServerConnection
     */
    public function connect(array $params, $username = null, $password = null, array $driverOptions = []): DriverConnection
    {
        return new SqlServerConnection(
            new Connection($params['pdo'])
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'pdo_sqlsrv';
    }
}
