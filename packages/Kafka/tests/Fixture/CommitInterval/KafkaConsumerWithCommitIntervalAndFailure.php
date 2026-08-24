<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\CommitInterval;

use Ecotone\Api\Header;
use Ecotone\Api\Kafka\KafkaConsumer;
use Ecotone\Api\QueryHandler;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use RuntimeException;

/**
 * licence Enterprise
 */
final class KafkaConsumerWithCommitIntervalAndFailure
{
    /**
     * @var array<array{payload: string}>
     */
    private array $messages = [];

    private bool $shouldFail = true;

    #[KafkaConsumer(
        'kafka_consumer_interval_3_with_failure',
        'testTopic',
        finalFailureStrategy: FinalFailureStrategy::RELEASE,
        commitIntervalInMessages: 3
    )]
    public function handle(string $payload, #[Header('fail')] bool $fail = false): void
    {
        // Fail on first call to message_6, succeed on retry
        if ($fail && $this->shouldFail) {
            $this->shouldFail = false;
            throw new RuntimeException('Simulated failure at message: ' . $payload);
        }

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
