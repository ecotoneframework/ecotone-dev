<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\AmqpConsumer;

use Ecotone\Amqp\Attribute\AmqpConsumer;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Enterprise
 */
final class AmqpConsumerAttributeExample
{
    /** @var string[] */
    private array $messagePayloads = [];

    #[AmqpConsumer(
        endpointId: 'amqp_consumer_attribute',
        queueName: 'test_queue')
    ]
    public function handle(#[Payload] string $payload, #[Header('fail')] bool $fail = false): void
    {
        if ($fail) {
            throw new \RuntimeException('Failed');
        }

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
