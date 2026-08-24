<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\KafkaConsumer;

use Ecotone\Api\Header;
use Ecotone\Api\InstantRetry;
use Ecotone\Api\Kafka\KafkaConsumer;
use Ecotone\Api\Payload;
use Ecotone\Api\QueryHandler;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use RuntimeException;

/**
 * licence Enterprise
 */
final class KafkaConsumerWithInstantRetryExample
{
    /** @var string[] */
    private array $messagePayloads = [];

    #[InstantRetry(retryTimes: 1)]
    #[KafkaConsumer('kafka_consumer_attribute', 'testTopicRetry', finalFailureStrategy: FinalFailureStrategy::IGNORE)]
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
