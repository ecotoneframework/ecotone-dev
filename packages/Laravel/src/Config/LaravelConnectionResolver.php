<?php

declare(strict_types=1);

namespace Ecotone\Laravel\Config;

use Ecotone\Dbal\DbalConnection;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Interop\Queue\ConnectionFactory;

/**
 * licence Apache-2.0
 */
final class LaravelConnectionResolver
{
    public static function resolveLaravelConnection(LaravelConnectionReference $connectionReference): ConnectionFactory
    {
        if (! class_exists(DbalConnection::class)) {
            throw new InvalidArgumentException('Dbal Module is not installed. Please install it first to make use of Database capabilities.');
        }

        return DbalConnection::create(DB::connection($connectionReference->getLaravelConnectionName())->getDoctrineConnection());
    }
}
