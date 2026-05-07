<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\KafkaConsumer;

use Ecotone\Kafka\Attribute\KafkaConsumer;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use RuntimeException;

/**
 * licence Enterprise
 */
final class ReplayableKafkaConsumerExample
{
    public const ENDPOINT_ID = 'replayable_kafka_consumer';
    public const TOPIC_REFERENCE = 'replayableKafkaTopic';

    public bool $shouldFail = true;
    public int $invocations = 0;
    /** @var string[] */
    public array $processedPayloads = [];

    #[KafkaConsumer(self::ENDPOINT_ID, self::TOPIC_REFERENCE)]
    public function handle(#[Payload] string $payload): void
    {
        $this->invocations++;
        if ($this->shouldFail) {
            throw new RuntimeException('simulated');
        }
        $this->processedPayloads[] = $payload;
    }
}
