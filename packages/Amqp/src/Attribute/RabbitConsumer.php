<?php

declare(strict_types=1);

namespace Ecotone\Amqp\Attribute;

use Attribute;
use Ecotone\Messaging\Attribute\MessageConsumer;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Support\Assert;
use Enqueue\AmqpLib\AmqpConnectionFactory;

/**
 * licence Enterprise
 */
#[Attribute]
final class RabbitConsumer extends MessageConsumer implements DefinedObject
{
    public function __construct(
        string $endpointId,
        private string $queueName,
        private FinalFailureStrategy $finalFailureStrategy = FinalFailureStrategy::STOP,
        private string $connectionReference = AmqpConnectionFactory::class,
    ) {
        Assert::notNullAndEmpty($queueName, "Queue name can't be empty");

        parent::__construct($endpointId);
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }

    public function getConnectionReference(): string
    {
        return $this->connectionReference;
    }

    public function getFinalFailureStrategy(): FinalFailureStrategy
    {
        return $this->finalFailureStrategy;
    }

    public function getDefinition(): Definition
    {
        return new Definition(
            self::class,
            [
                $this->getEndpointId(),
                $this->queueName,
                $this->connectionReference,
            ]
        );
    }
}
