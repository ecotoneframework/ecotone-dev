<?php

namespace Test\Ecotone\Amqp\Fixture\AmqpConsumer;

use Ecotone\Messaging\Attribute\ClassReference;
use Ecotone\Messaging\Attribute\MessageConsumer;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Modelling\Attribute\QueryHandler;

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
