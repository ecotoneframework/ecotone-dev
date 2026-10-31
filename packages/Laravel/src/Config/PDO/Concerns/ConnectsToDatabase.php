<?php

namespace Ecotone\Laravel\Config\PDO\Concerns;

use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\PDO\Exception;
use Ecotone\Laravel\Config\PDO\Connection;
use Ecotone\Laravel\Config\PDO\LostLaravelConnectionExceptionConverter;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * This file is a modified version of a class from the Laravel framework.
 *
 * Laravel is licensed under the MIT License.
 * Original authors: Taylor Otwell and the Laravel contributors.
 *
 * @license MIT (https://opensource.org/licenses/MIT)
 *
 * Modifications were made as part of the Ecotone framework under the Apache 2.0 License.
 * See LICENSE file for the Apache 2.0 License details.
 */
/**
 * licence Apache-2.0
 */
trait ConnectsToDatabase
{
    public function __construct(private string $laravelConnectionName)
    {
    }

    public function connect(array $params): DriverConnection
    {
        try {
            $laravelConnection = DB::connection($this->laravelConnectionName);
            $laravelConnection->reconnectIfMissingConnection();

            return new Connection($laravelConnection->getPdo());
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    public function getExceptionConverter(): ExceptionConverter
    {
        return new LostLaravelConnectionExceptionConverter(parent::getExceptionConverter(), $this->laravelConnectionName);
    }
}
