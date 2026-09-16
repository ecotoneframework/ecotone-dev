<?php

declare(strict_types=1);

namespace Ecotone\Redis\Configuration;

use Ecotone\Api\Redis\RedisConnectionReference;
use Ecotone\Enqueue\EnqueueMessageConsumerConfiguration;
use Ecotone\Redis\RedisInboundChannelAdapterBuilder;

/**
 * licence Apache-2.0
 */
final class RedisMessageConsumerConfiguration extends EnqueueMessageConsumerConfiguration
{
    private bool $declareOnStartup = RedisInboundChannelAdapterBuilder::DECLARE_ON_STARTUP_DEFAULT;

    public static function create(string $endpointId, string $queueName, string $connectionReferenceName = RedisConnectionReference::DEFAULT): self
    {
        return new self(
            $endpointId,
            $queueName,
            $connectionReferenceName
        );
    }

    public function withDeclareOnStartup(bool $declareOnStartup): self
    {
        $this->declareOnStartup = $declareOnStartup;

        return $this;
    }

    public function isDeclaredOnStartup(): bool
    {
        return $this->declareOnStartup;
    }
}
