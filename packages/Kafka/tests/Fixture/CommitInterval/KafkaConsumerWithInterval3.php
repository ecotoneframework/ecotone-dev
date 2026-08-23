<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\CommitInterval;

use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Kafka\Api\Attribute\KafkaConsumer;

/**
 * licence Enterprise
 */
final class KafkaConsumerWithInterval3
{
    /**
     * @var array<array{payload: string}>
     */
    private array $messages = [];

    #[KafkaConsumer('kafka_consumer_interval_3', 'testTopic', commitIntervalInMessages: 3)]
    public function handle(string $payload): void
    {
        $this->messages[] = ['payload' => $payload];
    }

    /**
     * @return array<array{payload: string}>
     */
    #[QueryHandler('consumer.getMessages')]
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function reset(): void
    {
        $this->messages = [];
    }
}
