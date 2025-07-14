<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\AmqpConsumer;

use Ecotone\Amqp\Attribute\RabbitConsumer;
use Ecotone\Modelling\Attribute\QueryHandler;
use stdClass;

/**
 * licence Enterprise
 */
final class AmqpConsumerAttributeWithObject
{
    /** @var string[] */
    private array $messagePayloads = [];

    #[RabbitConsumer(
        endpointId: 'amqp_consumer_attribute',
        queueName: 'test_queue'
    )
    ]
    public function handle(stdClass $payload): void
    {
        $this->messagePayloads[] = $payload;
    }

    /**
     * @return string[]
     */
    #[QueryHandler('consumer.getAttributeMessagePayloads')]
    public function getMessagePayloads(): array
    {
        return $this->messagePayloads;
    }
}
