<?php

declare(strict_types=1);

namespace App\Storage;

use Ecotone\Api\Dbal\DbalConnectionFactory;
use PDO;

final class InternalDatabaseConnectionFactory extends DbalConnectionFactory
{
    public function __construct(string $path, int $busyTimeoutSeconds)
    {
        parent::__construct(['dsn' => 'sqlite:///' . $path, 'connection' => ['driverClass' => InternalDatabaseDriver::class, 'driverOptions' => [PDO::ATTR_TIMEOUT => $busyTimeoutSeconds]]]);
    }
}
