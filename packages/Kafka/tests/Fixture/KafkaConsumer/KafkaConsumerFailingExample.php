<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\KafkaConsumer;

use Ecotone\Kafka\Attribute\KafkaConsumer;
use Ecotone\Messaging\Attribute\Parameter\Payload;
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
