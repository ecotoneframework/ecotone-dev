<?php

namespace Ecotone\Amqp\Configuration;

use Ecotone\Api\Amqp\AmqpConnectionReference;
use Ecotone\Enqueue\EnqueueMessageConsumerConfiguration;

/**
 * licence Apache-2.0
 */
class AmqpMessageConsumerConfiguration extends EnqueueMessageConsumerConfiguration
{
    public static function create(string $endpointId, string $queueName, string $amqpConnectionReferenceName = AmqpConnectionReference::DEFAULT): self
    {
        return new self($endpointId, $queueName, $amqpConnectionReferenceName);
    }
}
