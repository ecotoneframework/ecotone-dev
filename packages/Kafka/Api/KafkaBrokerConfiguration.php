<?php

declare(strict_types=1);

namespace Ecotone\Api\Kafka;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
     * @param $setupForTesting bool|null If true it will assume single partition for topics, which speed up partitioning process for running tests
     */
    public static function createWithDefaults(array $bootstrapServers = ['localhost:9092'], ?bool $setupForTesting = null): self
    {
        return new self($bootstrapServers, $setupForTesting);
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
