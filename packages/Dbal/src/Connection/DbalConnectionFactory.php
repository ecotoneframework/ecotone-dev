<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Connection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Exception;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;
use LogicException;
use ReflectionMethod;
use Throwable;

/**
 * licence MIT
 * code comes from https://github.com/php-enqueue/dbal
 */
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
            throw new LogicException('The config must be either an array of options, a DSN string or null');
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
        if ($this->connection) {
            try {
                if (method_exists($this->connection, 'close') && is_callable([$this->connection, 'close'])) {
                    $reflection = new ReflectionMethod($this->connection, 'close');
                    if ($reflection->isPublic()) {
                        $this->connection->close();
                    }
                }
            } catch (Throwable $e) {
            }

            $this->connection = null;
        }
    }

    public function establishConnection(): Connection
    {
        if (false == $this->connection) {
            $this->connection = DriverManager::getConnection($this->config['connection']);

            try {
                if (method_exists($this->connection, 'connect') && is_callable([$this->connection, 'connect'])) {
                    $reflection = new ReflectionMethod($this->connection, 'connect');
                    if ($reflection->isPublic()) {
                        $this->connection->connect();
                    } else {
                        $this->connection->getNativeConnection();
                    }
                } else {
                    $this->connection->getNativeConnection();
                }
            } catch (Exception $e) {
            }
        }

        return $this->connection;
    }

    private function parseDsn(string $dsn, ?array $config = null): array
    {
        $parsedDsn = $this->parseDsnComponents($dsn);

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

        if (false == isset($supported[$parsedDsn['scheme']])) {
            throw new LogicException(sprintf('The given DSN schema "%s" is not supported. There are supported schemes: "%s".', $parsedDsn['scheme'], implode('", "', array_keys($supported))));
        }

        $doctrineScheme = $supported[$parsedDsn['scheme']];

        if ($doctrineScheme === 'pdo_sqlite') {
            return $this->buildSqliteConfig($parsedDsn, $dsn, $config);
        }

        $dsnHasProtocolOnly = $parsedDsn['scheme'].':' === $dsn;
        if ($dsnHasProtocolOnly && is_array($config) && array_key_exists('connection', $config)) {
            $default = [
                'driver' => $doctrineScheme,
                'host' => 'localhost',
                'port' => ($doctrineScheme === 'pdo_pgsql' ? 5432 : 3306),
                'user' => 'root',
                'password' => '',
            ];

            return [
                'lazy' => true,
                'connection' => array_replace_recursive($default, $config['connection']),
            ];
        }

        return [
            'lazy' => true,
            'connection' => [
                'driver' => $doctrineScheme,
                'host' => $parsedDsn['host'] ?: 'localhost',
                'port' => $parsedDsn['port'] ?: ($doctrineScheme === 'pdo_pgsql' ? 5432 : 3306),
                'user' => $parsedDsn['user'] ?: 'root',
                'password' => $parsedDsn['password'] ?: '',
                'dbname' => ltrim($parsedDsn['path'] ?: '', '/') ?: '',
            ],
        ];
    }

    /**
     * Ported from Enqueue\Dsn\Dsn::parse(), which parse_url() cannot replace: parse_url() returns false
     * for DSNs such as "sqlite:///:memory:" or "sqlite:////tmp/x.db" because it tries (and fails) to
     * interpret the part after "//" as a host. The scheme/host/port are therefore extracted manually,
     * while user/password/path are still resolved with per-component parse_url() calls, which do not
     * fail on those DSNs the way a whole-string parse_url() call does.
     */
    private function parseDsnComponents(string $dsn): array
    {
        if (! str_contains($dsn, ':')) {
            throw new LogicException(sprintf('The given DSN "%s" is invalid.', $dsn));
        }

        [$scheme, $dsnWithoutScheme] = explode(':', $dsn, 2);
        $scheme = strtolower($scheme);

        if ($scheme === '' || ! preg_match('/^[a-z\d+\-.]*$/', $scheme)) {
            throw new LogicException(sprintf('The given DSN "%s" is invalid.', $dsn));
        }

        $user = parse_url($dsn, PHP_URL_USER) ?: null;
        if (is_string($user)) {
            $user = rawurldecode($user);
        }

        $password = parse_url($dsn, PHP_URL_PASS) ?: null;
        if (is_string($password)) {
            $password = rawurldecode($password);
        }

        $path = parse_url($dsn, PHP_URL_PATH) ?: null;
        if ($path) {
            $path = rawurldecode($path);
        }

        $host = null;
        $port = null;
        if (str_starts_with($dsnWithoutScheme, '//')) {
            $dsnWithoutScheme = substr($dsnWithoutScheme, 2);
            $dsnWithoutUserPassword = explode('@', $dsnWithoutScheme, 2);
            $dsnWithoutUserPassword = count($dsnWithoutUserPassword) === 2 ? $dsnWithoutUserPassword[1] : $dsnWithoutUserPassword[0];

            [$hostsPorts] = explode('#', $dsnWithoutUserPassword, 2);
            [$hostsPorts] = explode('?', $hostsPorts, 2);
            [$hostsPorts] = explode('/', $hostsPorts, 2);

            if (! empty($hostsPorts)) {
                $hostParts = explode(',', $hostsPorts);
                $firstHostPort = explode(':', $hostParts[0], 2);
                $host = $firstHostPort[0] !== '' ? rawurldecode($firstHostPort[0]) : null;
                $port = isset($firstHostPort[1]) ? (int) $firstHostPort[1] : null;
            }
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'password' => $password,
            'path' => $path,
        ];
    }

    private function buildSqliteConfig(array $parsedDsn, string $originalDsn, ?array $config = null): array
    {
        $path = $parsedDsn['path'];

        if ($path === null || $path === '') {
            $path = $this->extractSqlitePathFromDsn($originalDsn);
        }

        if ($path !== ':memory:') {
            $path = ltrim($path, '/');
            if (! str_starts_with($path, '/')) {
                $path = '/' . $path;
            }
        }

        $connectionConfig = [
            'driver' => 'pdo_sqlite',
            'path' => $path,
        ];

        if (is_array($config) && array_key_exists('connection', $config)) {
            $connectionConfig = array_replace_recursive($connectionConfig, $config['connection']);
        }

        return [
            'lazy' => true,
            'connection' => $connectionConfig,
        ];
    }

    private function extractSqlitePathFromDsn(string $dsn): string
    {
        if (preg_match('#^sqlite3?:///?(.*)$#', $dsn, $matches)) {
            $pathPart = $matches[1];
            if ($pathPart === '' || $pathPart === ':memory:') {
                return ':memory:';
            }
            if (str_starts_with($pathPart, '/')) {
                return $pathPart;
            }
            return '/' . $pathPart;
        }

        return ':memory:';
    }
}
