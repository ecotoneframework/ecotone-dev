<?php

namespace Ecotone\Laravel\Config\PDO;

use Doctrine\DBAL\Driver\AbstractSQLServerDriver;

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
class SqlServerDriver extends AbstractSQLServerDriver
{
    /**
     * Create a new database connection.
     *
     * @param  mixed[]  $params
     * @param  string|null  $username
     * @param  string|null  $password
     * @param  mixed[]  $driverOptions
     * @return \Ecotone\Laravel\Config\PDO\SqlServerConnection
     */
    public function connect(array $params, $username = null, $password = null, array $driverOptions = [])
    {
        return new SqlServerConnection(
            new Connection($params['pdo'])
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'pdo_sqlsrv';
    }
}
