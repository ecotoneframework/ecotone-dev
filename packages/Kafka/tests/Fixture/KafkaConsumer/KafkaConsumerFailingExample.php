<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\KafkaConsumer;

use Ecotone\Api\Attribute\Parameter\Payload;
use Ecotone\Kafka\Api\Attribute\KafkaConsumer;
use RuntimeException;

/**
 * licence Enterprise
 */
final class KafkaConsumerFailingExample
{
    #[KafkaConsumer('kafka_consumer_attribute', 'testTopicFailure')]
    public function handle(#[Payload] string $payload): void
    {
        throw new RuntimeException('Failed');
    }
}
