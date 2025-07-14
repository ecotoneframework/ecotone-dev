<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\KafkaConsumer;

use Ecotone\Kafka\Attribute\KafkaConsumer;
use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Modelling\Attribute\InstantRetry;
use Ecotone\Modelling\Attribute\QueryHandler;
use RuntimeException;

/**
 * licence Enterprise
 */
final class KafkaConsumerWithInstantRetryAndErrorChannelExample
{
    /** @var string[] */
    private array $messagePayloads = [];

    #[InstantRetry(retryTimes: 1)]
    #[ErrorChannel('customErrorChannel')]
    #[KafkaConsumer('kafka_consumer_attribute', 'testTopicError', finalFailureStrategy: FinalFailureStrategy::RESEND)]
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
