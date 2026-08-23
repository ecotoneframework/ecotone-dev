<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\KafkaConsumer;

use Ecotone\Api\Attribute\Parameter\Header;
use Ecotone\Api\Attribute\Parameter\Payload;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Kafka\Api\Attribute\KafkaConsumer;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use RuntimeException;

/**
 * licence Enterprise
 */
final class KafkaConsumerWithFailStrategyExample
{
    /** @var string[] */
    private array $messagePayloads = [];

    #[KafkaConsumer('kafka_consumer_attribute', 'testTopicFailure', finalFailureStrategy: FinalFailureStrategy::IGNORE)]
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
