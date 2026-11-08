<?php

namespace Ecotone\Laravel\Config\PDO;

use Doctrine\DBAL\Driver\AbstractSQLServerDriver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\PDO\Exception;
use Ecotone\Dbal\DbalTransaction\TransactionReconnectProtectionAwareDriver;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * licence MIT
 */
class SqlServerDriver extends AbstractSQLServerDriver implements TransactionReconnectProtectionAwareDriver
{
    private ?Connection $connection = null;

    public function __construct(private string $laravelConnectionName)
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
        try {
            $laravelConnection = DB::connection($this->laravelConnectionName);
            $laravelConnection->reconnectIfMissingConnection();

            return new SqlServerConnection(
                $this->connection = new Connection($laravelConnection)
            );
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    public function leaveTransactionBoundary(): void
    {
        $this->connection?->endLostTransaction();
    }

    public function getExceptionConverter(): ExceptionConverter
    {
        return new LostLaravelConnectionExceptionConverter(parent::getExceptionConverter(), $this->laravelConnectionName);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'pdo_sqlsrv';
    }
}
