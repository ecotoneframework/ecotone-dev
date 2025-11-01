<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\CommitInterval;

use Ecotone\Kafka\Attribute\KafkaConsumer;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Enterprise
 */
final class KafkaConsumerWithInterval10
{
    /**
     * @var array<array{payload: string}>
     */
    private array $messages = [];

    #[KafkaConsumer('kafka_consumer_interval_10', 'testTopic', commitIntervalInMessages: 10)]
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
        $this->messages[] = [];
    }
}

