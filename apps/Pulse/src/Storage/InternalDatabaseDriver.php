<?php

declare(strict_types=1);

namespace App\Storage;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver;

final class InternalDatabaseDriver extends AbstractDriverMiddleware
{
    public function __construct()
    {
        parent::__construct(new Driver());
    }

    public function connect(array $params): Connection
    {
        return new WriteTransactionsOneAtATime(parent::connect($params));
    }
}
