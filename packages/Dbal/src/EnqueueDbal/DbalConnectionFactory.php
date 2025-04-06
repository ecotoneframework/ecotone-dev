<?php

declare(strict_types=1);

namespace Enqueue\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Enqueue\Dsn\Dsn;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;

class DbalConnectionFactory implements ConnectionFactory
{
    /**
     * @var array
     */
    private $config;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * The config could be an array, string DSN or null. In case of null it will attempt to connect to mysql localhost with default credentials.
     *
     * $config = [
     *   'connection' => []             - dbal connection options. see http://docs.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html
     *   'table_name' => 'enqueue',     - database table name.
     *   'polling_interval' => '1000',  - How often query for new messages (milliseconds)
     *   'lazy' => true,                - Use lazy database connection (boolean)
     * ]
     *
     * or
     *
     * mysql://user:pass@localhost:3606/db?charset=UTF-8
     *
     * @param array|string|null $config
     */
    public function __construct($config = 'mysql:')
    {
        if (empty($config)) {
            $config = $this->parseDsn('mysql:');
        } elseif (is_string($config)) {
            $config = $this->parseDsn($config);
        } elseif (is_array($config)) {
            if (array_key_exists('dsn', $config)) {
                $config = array_replace_recursive($config, $this->parseDsn($config['dsn'], $config));
                unset($config['dsn']);
            }
        } else {
            throw new \LogicException('The config must be either an array of options, a DSN string or null');
        }

        $this->config = array_replace_recursive([
            'connection' => [],
            'table_name' => 'enqueue',
            'polling_interval' => 1000,
            'lazy' => true,
        ], $config);
    }

    /**
     * @return DbalContext
     */
    public function createContext(): Context
    {
        if ($this->config['lazy']) {
            return new DbalContext(function () {
                return $this->establishConnection();
            }, $this->config);
        }

        return new DbalContext($this->establishConnection(), $this->config);
    }

    public function close(): void
    {
        // In DBAL 4.x, close() is protected, so we can't call it directly
        // The connection will be closed automatically when the object is destroyed
        $this->connection = null;
    }

    private function establishConnection(): Connection
    {
        if (false == $this->connection) {
            // Ensure we have a driver specified for Doctrine DBAL 4.x compatibility
            if (empty($this->config['connection']['driver']) && empty($this->config['connection']['driverClass']) && empty($this->config['connection']['url'])) {
                // Default to pdo_mysql if no driver is specified
                $this->config['connection']['driver'] = 'pdo_mysql';
            }

            $this->connection = DriverManager::getConnection($this->config['connection']);

            // In DBAL 4.x, connect() is protected, so we'll use a different approach
            // to ensure the connection is established
            try {
                $this->connection->getNativeConnection();
            } catch (\Exception $e) {
                // Connection failed, but we've already tried our best
            }
        }

        return $this->connection;
    }

    private function parseDsn(string $dsn, array $config = null): array
    {
        $parsedDsn = Dsn::parseFirst($dsn);

        $supported = [
            'db2' => 'ibm_db2',
            'ibm-db2' => 'ibm_db2',
            'mssql' => 'pdo_sqlsrv',
            'sqlsrv+pdo' => 'pdo_sqlsrv',
            'mysql' => 'pdo_mysql',
            'mysql2' => 'pdo_mysql',
            'mysql+pdo' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'postgres' => 'pdo_pgsql',
            'pgsql+pdo' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            'sqlite3' => 'pdo_sqlite',
            'sqlite+pdo' => 'pdo_sqlite',
        ];

        if ($parsedDsn && false == isset($supported[$parsedDsn->getScheme()])) {
            throw new \LogicException(sprintf('The given DSN schema "%s" is not supported. There are supported schemes: "%s".', $parsedDsn->getScheme(), implode('", "', array_keys($supported))));
        }

        $doctrineScheme = $supported[$parsedDsn->getScheme()];
        $dsnHasProtocolOnly = $parsedDsn->getScheme().':' === $dsn;
        if ($dsnHasProtocolOnly && is_array($config) && array_key_exists('connection', $config)) {
            $default = [
                'driver' => $doctrineScheme,
                'host' => 'localhost',
                'port' => '3306',
                'user' => 'root',
                'password' => '',
            ];

            return [
                'lazy' => true,
                'connection' => array_replace_recursive($default, $config['connection']),
            ];
        }

        $url = $dsnHasProtocolOnly ?
            $doctrineScheme.'://root@localhost' :
            str_replace($parsedDsn->getScheme(), $doctrineScheme, $dsn);

        return [
            'lazy' => true,
            'connection' => [
                'driver' => $doctrineScheme,
                'url' => $url,
                'host' => $parsedDsn->getHost() ?: 'localhost',
                'port' => $parsedDsn->getPort() ?: ($doctrineScheme === 'pdo_pgsql' ? 5432 : 3306),
                'user' => $parsedDsn->getUser() ?: 'root',
                'password' => $parsedDsn->getPassword() ?: '',
                'dbname' => ltrim($parsedDsn->getPath() ?: '', '/') ?: 'ecotone',
            ],
        ];
    }
}
