<?php

namespace Ecotone\Sqs\Configuration;

use Ecotone\Enqueue\EnqueueMessageConsumerConfiguration;
use Enqueue\Sqs\SqsConnectionFactory;

final class SqsMessageConsumerConfiguration extends EnqueueMessageConsumerConfiguration
{
    public static function create(string $endpointId, string $queueName, string $amqpConnectionReferenceName = SqsConnectionFactory::class): self
    {
        return new self($endpointId, $queueName, $amqpConnectionReferenceName);
    }
}
