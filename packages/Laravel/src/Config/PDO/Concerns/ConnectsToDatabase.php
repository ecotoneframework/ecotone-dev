<?php

namespace Ecotone\Laravel\Config\PDO\Concerns;

use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Ecotone\Laravel\Config\PDO\Connection;
use Ecotone\Laravel\Config\PDO\LostLaravelConnectionExceptionConverter;
use Illuminate\Database\Connection as LaravelConnection;

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
    public function __construct(private LaravelConnection $laravelConnection)
    {
    }

    public function connect(array $params): DriverConnection
    {
        $this->laravelConnection->reconnectIfMissingConnection();

        return new Connection($this->laravelConnection->getPdo());
    }

    public function getExceptionConverter(): ExceptionConverter
    {
        return new LostLaravelConnectionExceptionConverter(parent::getExceptionConverter(), $this->laravelConnection);
    }
}
