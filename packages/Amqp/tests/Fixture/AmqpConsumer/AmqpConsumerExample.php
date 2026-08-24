<?php

namespace Test\Ecotone\Amqp\Fixture\AmqpConsumer;

use Ecotone\Api\Attribute\ClassReference;
use Ecotone\Api\Attribute\Parameter\Payload;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Messaging\Attribute\MessageConsumer;

#[ClassReference(AmqpConsumerExample::class)]
/**
 * licence Apache-2.0
 */
class AmqpConsumerExample
{
    /** @var string[] */
    private array $messagePayloads = [];

    #[MessageConsumer('asynchronous_endpoint')]
    public function handle(#[Payload] string $payload): void
    {
        $this->messagePayloads[] = $payload;
    }

    /**
     * @return string[]
     */
    #[QueryHandler('consumer.getMessagePayloads')]
    public function getMessagePayloads(): array
    {
        return $this->messagePayloads;
    }
}
