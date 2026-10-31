<?php

declare(strict_types=1);

namespace Ecotone\Laravel\Config\PDO;

use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Support\Facades\DB;

/**
 * licence Apache-2.0
 */
final class LostLaravelConnectionExceptionConverter implements ExceptionConverter
{
    use DetectsLostConnections;

    public function __construct(private ExceptionConverter $exceptionConverter, private string $laravelConnectionName)
    {
    }

    public function convert(Exception $exception, ?Query $query): DriverException
    {
        if (! $this->causedByLostConnection($exception)) {
            return $this->exceptionConverter->convert($exception, $query);
        }

        DB::connection($this->laravelConnectionName)->disconnect();

        return new ConnectionLost($exception, $query);
    }
}
