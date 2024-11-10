<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Configuration;

/**
 * licence Enterprise
 */
final class KafkaBrokerConfiguration
{
    public function __construct(
        private array $bootstrapServers,
        private ?bool $setupForTesting = null
    ) {

    }

    /**
     * @param array<string> $bootstrapServers [broker-one:9092, broker-two:9092]
     */
    public static function createWithDefaults(array $bootstrapServers = ['localhost:9092'], ?bool $setupForTesting = null): self
    {
        return new self($bootstrapServers);
    }

    public function getBootstrapServers(): array
    {
        return $this->bootstrapServers;
    }

    /**
     * @return bool|null When true, it means that Kafka is setup for testing and will use for example single partition for topics. When null Ecotone will discover whatever it's test mode or not
     */
    public function isSetupForTesting(): ?bool
    {
        return $this->setupForTesting;
    }
}
