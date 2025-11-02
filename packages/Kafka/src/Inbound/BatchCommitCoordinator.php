<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Inbound;

use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use RdKafka\KafkaConsumer;
use RdKafka\Message as KafkaMessage;

/**
 * Coordinates batch commits for Kafka offset tracking
 *
 * Tracks messages processed in the current batch and determines when to commit
 * based on the configured commit interval.
 *
 * licence Enterprise
 */
class BatchCommitCoordinator
{
    /**
     * @var array<string, array<string, int>> key is topic name, value is the number of messages handled in the batch
     */
    private array $messagesHandledInBatch = [];
    private int $messagesHandledTotal = 0;

    public function __construct(
        private int $commitInterval,
        public readonly KafkaConsumer $consumer,
        private LoggingGateway $loggingGateway,
    ) {
    }

    /**
     * Record that a message was processed and commit if interval reached
     *
     * @param KafkaMessage $message The Kafka message that was processed
     */
    public function increaseMessageHandling(KafkaMessage $message): void
    {
        $this->messagesHandledTotal++;
        $this->messagesHandledInBatch[$message->topic_name][$message->partition] = $message->offset;

        if ($this->shouldCommit()) {
            $this->forceCommitAll();
        }
    }

    public function forceCommitAll(): void
    {
        try {
            $topicPartitions = [];
            foreach ($this->messagesHandledInBatch as $topic => $partitions) {
                foreach ($partitions as $partition => $offset) {
                    $topicPartitions[] = new \RdKafka\TopicPartition(
                        $topic,
                        $partition,
                        $offset + 1, // pointer to next message to consume
                    );
                }
            }

            if (count($topicPartitions) === 0) {
                return;
            }

            try {
                $this->consumer->commit($topicPartitions);
                $this->loggingGateway->info('Message batch committed', [
                    'topic_partitions' => $topicPartitions,
                ]);
            } catch (\RdKafka\Exception $exception) {
                $this->loggingGateway->error('Failed to commit message batch: ' . $exception->getMessage(), [
                    'exception' => $exception,
                    'topic_partitions' => $topicPartitions,
                ]);
            }
        } finally {
            $this->reset();
        }
    }

    /**
     * Check if we should commit based on the interval
     */
    private function shouldCommit(): bool
    {
        return $this->messagesHandledTotal >= $this->commitInterval;
    }

    /**
     * Reset the batch counter (used after failures)
     */
    private function reset(): void
    {
        $this->messagesHandledInBatch = [];
        $this->messagesHandledTotal = 0;
    }
}
