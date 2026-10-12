<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\CommitInterval;

use Ecotone\Api\Attribute\Header;
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
