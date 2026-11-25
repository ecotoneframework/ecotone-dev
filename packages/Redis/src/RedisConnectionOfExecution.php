<?php

declare(strict_types=1);

namespace Ecotone\Redis;

use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Execution\ExecutionScope;
use Enqueue\Redis\Redis;

/**
 * licence Apache-2.0
 */
final class RedisConnectionOfExecution
{
    public ?Redis $redis = null;

    public static function refuseConnectionOfTheApplicationInsideACoroutine(): void
    {
        if (! ExecutionScope::isInsideCoroutine()) {
            return;
        }

        throw ConfigurationException::create(
            'RedisConnectionFactory was given a Redis client the application connected itself, and is used inside a Swoole or OpenSwoole coroutine. '
            . 'That client is one connection for the whole process, and two coroutines reading from one connection at once stop the process. '
            . "Pass the connection settings instead, `new RedisConnectionFactory('redis://host:6379')` or `new RedisConnectionFactory(['dsn' => 'redis+phpredis://host:6379'])`, so every coroutine opens a connection of its own, "
            . 'or handle one message at a time: set `enable_coroutine` to false on the server and run consumers without the `coroutines` option.'
        );
    }

    public function __destruct()
    {
        $this->redis?->disconnect();
    }
}
