<?php

declare(strict_types=1);

namespace Test\Ecotone\Redis;

use Enqueue\Redis\RedisConnectionFactory;
use Interop\Queue\ConnectionFactory;
use PHPUnit\Framework\TestCase;

abstract class AbstractConnectionTest extends TestCase
{
    private ?RedisConnectionFactory $connectionFactory = null;

    public function getConnectionFactory(): ConnectionFactory
    {
        if (! $this->connectionFactory) {
            $this->connectionFactory = self::getConnection();
        }

        return $this->connectionFactory;
    }

    public static function getConnection(): RedisConnectionFactory
    {
        return new RedisConnectionFactory(
            getenv('REDIS_DSN') ? getenv('REDIS_DSN') : 'redis://localhost:6379'
        );
    }
}
