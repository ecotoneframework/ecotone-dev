<?php

declare(strict_types=1);

namespace Ecotone\Api\Kafka;

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
final class KafkaBrokerConfiguration
{
    public function __construct(
        public readonly array $bootstrapServers,
        /**
         * When true, Kafka is set up for testing and uses, for example, a single partition for topics. When null, Ecotone discovers whether it runs in test mode
         */
        public readonly ?bool $setupForTesting = null
    ) {
    }

    /**
     * @param array<string> $bootstrapServers [broker-one:9092, broker-two:9092]
     * @param $setupForTesting bool|null If true it will assume single partition for topics, which speed up partitioning process for running tests
     */
    public static function createWithDefaults(array $bootstrapServers = ['localhost:9092'], ?bool $setupForTesting = null): self
    {
        return new self($bootstrapServers, $setupForTesting);
    }
}
