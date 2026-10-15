<?php

namespace Ecotone\Sqs\Configuration;

use Ecotone\Api\Sqs\SqsConnectionReference;
use Ecotone\Enqueue\EnqueueMessageConsumerConfiguration;
use Ecotone\Sqs\SqsInboundChannelAdapterBuilder;

/**
 * licence Apache-2.0
 */
final class SqsMessageConsumerConfiguration extends EnqueueMessageConsumerConfiguration
{
    private bool $declareOnStartup = SqsInboundChannelAdapterBuilder::DECLARE_ON_STARTUP_DEFAULT;

    public static function create(string $endpointId, string $queueName, string $amqpConnectionReferenceName = SqsConnectionReference::DEFAULT): self
    {
        return new self($endpointId, $queueName, $amqpConnectionReferenceName);
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
