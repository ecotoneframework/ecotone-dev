<?php

namespace Ecotone\Laravel\Config\PDO;

use Doctrine\DBAL\Driver\AbstractSQLServerDriver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Illuminate\Database\Connection as LaravelConnection;

/**
 * licence MIT
 */
class SqlServerDriver extends AbstractSQLServerDriver
{
    public function __construct(private LaravelConnection $laravelConnection)
    {
    }

    /**
     * Create a new database connection.
     *
     * @param  mixed[]  $params
     * @param  string|null  $username
     * @param  string|null  $password
     * @param  mixed[]  $driverOptions
     */
    public function connect(array $params, $username = null, $password = null, array $driverOptions = []): DriverConnection
    {
        $this->laravelConnection->reconnectIfMissingConnection();

        return new SqlServerConnection(
            new Connection($this->laravelConnection->getPdo())
        );
    }

    public function getExceptionConverter(): ExceptionConverter
    {
        return new LostLaravelConnectionExceptionConverter(parent::getExceptionConverter(), $this->laravelConnection);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'pdo_sqlsrv';
    }
}
