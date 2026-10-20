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

    public static function fromDsn(string $dsn): self
    {
        foreach (self::schemes() as $scheme => $transport) {
            if (str_starts_with($dsn, $scheme)) {
                return $transport;
            }
        }

        throw new PulseConfigurationException(sprintf(
            'PULSE_TRANSPORT_DSN "%s" does not start with a supported scheme, so Pulse cannot tell which broker to connect to. Use one of: %s.',
            $dsn,
            implode(', ', array_map(static fn (string $scheme, self $transport) => sprintf('%s (%s)', $scheme, $transport->name), array_keys(self::schemes()), self::schemes()))
        ));
    }

    /**
     * @return array<string>
     */
    public function modulePackages(): array
    {
        return [ModulePackageList::CORE_PACKAGE, ModulePackageList::SYMFONY_PACKAGE, match ($this) {
            self::Amqp => ModulePackageList::AMQP_PACKAGE,
            self::Redis => ModulePackageList::REDIS_PACKAGE,
            self::Sqs => ModulePackageList::SQS_PACKAGE,
            self::Kafka => ModulePackageList::KAFKA_PACKAGE,
            self::Dbal => ModulePackageList::DBAL_PACKAGE,
        }];
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
