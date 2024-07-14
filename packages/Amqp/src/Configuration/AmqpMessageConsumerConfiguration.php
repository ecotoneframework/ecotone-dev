<?php

namespace Ecotone\Amqp\Configuration;

use Ecotone\Enqueue\EnqueueMessageConsumerConfiguration;
use Enqueue\AmqpExt\AmqpConnectionFactory;

/**
 * licence Apache-2.0
 */
class AmqpMessageConsumerConfiguration extends EnqueueMessageConsumerConfiguration
{
    public static function create(string $endpointId, string $queueName, string $amqpConnectionReferenceName = AmqpConnectionFactory::class): self
    {
        return new self($endpointId, $queueName, $amqpConnectionReferenceName);
    }
}
