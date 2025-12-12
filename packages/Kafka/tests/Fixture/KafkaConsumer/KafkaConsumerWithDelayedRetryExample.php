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
final class KafkaConsumerWithDelayedRetryExample
{
    /** @var array<array{payload: string, attempt: int, timestamp: int}> */
    private array $messageAttempts = [];

    private int $failureCount = 0;

    /**
     * Kafka Consumer with multi-level retry:
     * 1. Instant Retry: 1 immediate retry
     * 2. Error Channel: Routes to 'delayedRetryChannel' for delayed retry
     * 3. Final Failure: After all retries, resends to Kafka topic
     */
    #[InstantRetry(retryTimes: 1)]
    #[ErrorChannel('delayedRetryChannel')]
    #[KafkaConsumer('kafka_consumer_delayed_retry', 'testTopicDelayedRetry', finalFailureStrategy: FinalFailureStrategy::RESEND)]
    public function handle(#[Payload] string $payload, #[Header('fail')] bool $fail = false): void
    {
        $this->messageAttempts[] = [
            'payload' => $payload,
            'attempt' => count(array_filter($this->messageAttempts, fn ($m) => $m['payload'] === $payload)) + 1,
            'timestamp' => (int)(microtime(true) * 1000),
        ];

        if ($fail && $this->failureCount < 3) {
            $this->failureCount++;
            throw new RuntimeException('Simulated failure - attempt ' . $this->failureCount);
        }
    }

    /**
     * @return array<array{payload: string, attempt: int, timestamp: int}>
     */
    #[QueryHandler('consumer.getDelayedRetryAttempts')]
    public function getMessageAttempts(): array
    {
        return $this->messageAttempts;
    }

    #[QueryHandler('consumer.getFailureCount')]
    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    public function reset(): void
    {
        $this->messageAttempts = [];
        $this->failureCount = 0;
    }
}
