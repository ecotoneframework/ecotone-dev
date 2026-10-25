<?php

declare(strict_types=1);

namespace App\Settings;

use Ecotone\Api\ExtensionObject\ModulePackageList;

enum Transport: string
{
    case Amqp = 'amqp';
    case Redis = 'redis';
    case Sqs = 'sqs';
    case Kafka = 'kafka';
    case Dbal = 'dbal';

    public static function fromDsn(string $dsn, string $variable = 'PULSE_TRANSPORT_DSN'): self
    {
        foreach (self::schemes() as $scheme => $transport) {
            if (str_starts_with(strtolower($dsn), $scheme)) {
                return $transport;
            }
        }

        throw new PulseConfigurationException(sprintf(
            '%s "%s" does not start with a supported scheme (matched case-insensitively), so Pulse cannot tell which broker to connect to. Use one of: %s.',
            $variable,
            $dsn,
            implode(', ', array_map(static fn (string $scheme, self $transport) => sprintf('%s (%s)', $scheme, $transport->name), array_keys(self::schemes()), self::schemes()))
        ));
    }

    /**
     * @return array<string>
     */
    public function modulePackages(): array
    {
        return [ModulePackageList::CORE_PACKAGE, ModulePackageList::SYMFONY_PACKAGE, ModulePackageList::DBAL_PACKAGE, match ($this) {
            self::Amqp => ModulePackageList::AMQP_PACKAGE,
            self::Redis => ModulePackageList::REDIS_PACKAGE,
            self::Sqs => ModulePackageList::SQS_PACKAGE,
            self::Kafka => ModulePackageList::KAFKA_PACKAGE,
            self::Dbal => ModulePackageList::DBAL_PACKAGE,
        }];
    }

    public function destinationKind(): string
    {
        return $this === self::Kafka ? 'topic' : 'queue';
    }

    public function channelBuilder(): string
    {
        return match ($this) {
            self::Amqp => 'AmqpBackedMessageChannelBuilder',
            self::Redis => 'RedisBackedMessageChannelBuilder',
            self::Sqs => 'SqsBackedMessageChannelBuilder',
            self::Kafka => 'KafkaMessageChannelBuilder',
            self::Dbal => 'DbalBackedMessageChannelBuilder',
        };
    }

    public function channelBuilderClass(): string
    {
        return match ($this) {
            self::Amqp => 'Ecotone\\Api\\Amqp\\AmqpBackedMessageChannelBuilder',
            self::Redis => 'Ecotone\\Api\\Redis\\RedisBackedMessageChannelBuilder',
            self::Sqs => 'Ecotone\\Api\\Sqs\\SqsBackedMessageChannelBuilder',
            self::Kafka => 'Ecotone\\Api\\Kafka\\KafkaMessageChannelBuilder',
            self::Dbal => 'Ecotone\\Api\\Dbal\\ExtensionObject\\DbalBackedMessageChannelBuilder',
        };
    }

    public function hasDestinationOfItsOwn(): bool
    {
        return $this === self::Amqp || $this === self::Kafka;
    }

    /**
     * @return array<string, self>
     */
    private static function schemes(): array
    {
        return [
            'amqp://' => self::Amqp,
            'amqps://' => self::Amqp,
            'redis://' => self::Redis,
            'sqs:' => self::Sqs,
            'kafka://' => self::Kafka,
            'pgsql://' => self::Dbal,
            'mysql://' => self::Dbal,
            'sqlite://' => self::Dbal,
        ];
    }
}
