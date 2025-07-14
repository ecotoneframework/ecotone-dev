<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\AmqpConsumer;

use Ecotone\Amqp\Attribute\RabbitConsumer;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Modelling\Attribute\QueryHandler;
use RuntimeException;

/**
 * licence Enterprise
 */
final class AmqpConsumerWithFailStrategyAttributeExample
{
    /** @var string[] */
    private array $messagePayloads = [];

    #[RabbitConsumer('amqp_consumer_attribute', 'test_queue', finalFailureStrategy: FinalFailureStrategy::IGNORE)]
    public function handle(#[Payload] string $payload, #[Header('fail')] bool $fail = false): void
    {
        $this->messagePayloads[] = $payload;

        if ($fail) {
            throw new RuntimeException('Failed');
        }
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
