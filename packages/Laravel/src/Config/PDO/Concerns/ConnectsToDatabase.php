<?php

namespace Ecotone\Laravel\Config\PDO\Concerns;

use Ecotone\Laravel\Config\PDO\Connection;
use InvalidArgumentException;
use PDO;

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
    /**
     * Create a new database connection.
     *
     * @param mixed[] $params
     * @param string|null $username
     * @param string|null $password
     * @param mixed[] $driverOptions
     * @return Connection
     *
     * @throws InvalidArgumentException
     */
    public function connect(array $params, $username = null, $password = null, array $driverOptions = [])
    {
        if (! isset($params['pdo']) || ! $params['pdo'] instanceof PDO) {
            throw new InvalidArgumentException('Laravel requires the "pdo" property to be set and be a PDO instance.');
        }

        return new Connection($params['pdo']);
    }
}
