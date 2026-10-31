<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config\PDO;

use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query;
use Throwable;

/**
 * licence Apache-2.0
 */
final class LostTempestConnectionExceptionConverter implements ExceptionConverter
{
    private const LOST_CONNECTION_MESSAGES = [
        'no connection to the server',
        'server closed the connection unexpectedly',
        'terminating connection',
        'SSL connection has been closed unexpectedly',
        'server has gone away',
        'Lost connection to',
        'disconnected by the server',
        'Error while sending',
        'is dead or not enabled',
        'Broken pipe',
    ];

    public function __construct(private ExceptionConverter $exceptionConverter, private ?string $configTag)
    {
    }

    public function convert(Exception $exception, ?Query $query): DriverException
    {
        if (! self::isCausedByLostConnection($exception)) {
            return $this->exceptionConverter->convert($exception, $query);
        }

        TempestConnectionPdo::disconnect($this->configTag);

        return new ConnectionLost($exception, $query);
    }

    public static function isCausedByLostConnection(Throwable $exception): bool
    {
        foreach (self::LOST_CONNECTION_MESSAGES as $message) {
            if (str_contains($exception->getMessage(), $message)) {
                return true;
            }
        }

        return false;
    }
}
