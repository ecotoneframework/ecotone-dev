<?php

declare(strict_types=1);

namespace Ecotone\Laravel\Config\PDO;

use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query;
use Illuminate\Database\Connection as LaravelConnection;
use Illuminate\Database\DetectsLostConnections;

/**
 * licence Apache-2.0
 */
final class LostLaravelConnectionExceptionConverter implements ExceptionConverter
{
    use DetectsLostConnections;

    public function __construct(private ExceptionConverter $exceptionConverter, private LaravelConnection $laravelConnection)
    {
    }

    public function convert(Exception $exception, ?Query $query): DriverException
    {
        if (! $this->causedByLostConnection($exception)) {
            return $this->exceptionConverter->convert($exception, $query);
        }

        $this->laravelConnection->disconnect();

        return new ConnectionLost($exception, $query);
    }
}
