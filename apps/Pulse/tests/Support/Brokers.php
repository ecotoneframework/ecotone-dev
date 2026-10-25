<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class Brokers
{
    public static function amqpDsn(string $user = 'guest', string $password = 'guest'): string
    {
        return sprintf('amqp://%s:%s@%s/%%2f', $user, $password, self::amqpAddress());
    }

    public static function amqpAddress(): string
    {
        $parts = parse_url(getenv('RABBIT_HOST') ?: 'amqp://rabbitmq:5672');

        return ($parts['host'] ?? 'rabbitmq') . ':' . ($parts['port'] ?? 5672);
    }

    public static function kafkaDsn(): string
    {
        return 'kafka://' . (getenv('KAFKA_DSN') ?: 'kafka:9092');
    }

    public static function redisDsn(): string
    {
        return getenv('REDIS_DSN') ?: 'redis://redis:6379';
    }
}
