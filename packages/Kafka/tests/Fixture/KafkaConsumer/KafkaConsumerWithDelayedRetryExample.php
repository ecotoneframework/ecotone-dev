<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\KafkaConsumer;

use Ecotone\Api\Attribute\ErrorChannel;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InstantRetry;
use Ecotone\Api\Attribute\Payload;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\FinalFailureStrategy;
use Ecotone\Api\Kafka\KafkaConsumer;
use RuntimeException;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
