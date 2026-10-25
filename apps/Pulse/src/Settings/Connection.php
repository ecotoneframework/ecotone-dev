<?php

declare(strict_types=1);

namespace App\Settings;

use Ecotone\Api\Amqp\AmqpExtConnectionFactory;
use Ecotone\Api\Redis\RedisConnectionFactory;
use Ecotone\Api\Sqs\SqsConnectionFactory;

final readonly class Connection
{
    public const DEFAULT = 'default';

    public function __construct(
        public string $name,
        public string $dsn,
        public Transport $transport,
        public string $environmentVariable,
        public string $replyDestination,
    ) {
    }

    public function isDefault(): bool
    {
        return $this->name === self::DEFAULT;
    }

    public function referenceName(): ?string
    {
        if (! $this->isDefault()) {
            return PulseSettings::TRANSPORT_CONNECTION_REFERENCE . '_' . $this->name;
        }

        return match ($this->transport) {
            Transport::Amqp => AmqpExtConnectionFactory::class,
            Transport::Redis => RedisConnectionFactory::class,
            Transport::Sqs => SqsConnectionFactory::class,
            Transport::Dbal => PulseSettings::TRANSPORT_CONNECTION_REFERENCE,
            Transport::Kafka => null,
        };
    }

    /**
     * @return array{dsn: string, scheme_extensions?: list<string>}
     */
    public function clientConfiguration(): array
    {
        return $this->transport === Transport::Redis
            ? ['dsn' => $this->dsn, 'scheme_extensions' => ['phpredis']]
            : ['dsn' => $this->dsn];
    }

    /**
     * @return list<string>
     */
    public function kafkaBootstrapServers(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) preg_replace('#^kafka://#i', '', $this->dsn)))));
    }
}
