<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Configuration;

/**
 * licence Enterprise
 */
final class KafkaBrokerConfiguration
{
    public function __construct(private array $bootstrapServers)
    {

    }

    /**
     * @param array<string> $bootstrapServers [broker-one:9092, broker-two:9092]
     */
    public static function createWithDefaults(array $bootstrapServers = ['localhost:9092']): self
    {
        return new self($bootstrapServers);
    }

    public function getBootstrapServers(): array
    {
        return $this->bootstrapServers;
    }
}
