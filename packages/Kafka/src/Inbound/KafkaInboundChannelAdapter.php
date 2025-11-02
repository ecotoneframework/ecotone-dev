<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Inbound;

use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessagePoller;
use Ecotone\Messaging\MessagingException;

/**
 * licence Enterprise
 */
final class KafkaInboundChannelAdapter implements MessagePoller
{
    public const MINIMUM_REQUIRED_TIME_FOR_LOAD_BALANCING = 10000;
    private ?BatchCommitCoordinator $batchCommitCoordinator = null;

    public function __construct(
        private string                     $endpointId,
        protected KafkaAdmin                 $kafkaAdmin,
        protected InboundMessageConverter    $inboundMessageConverter,
        protected ConversionService          $conversionService,
        protected int                       $receiveTimeoutInMilliseconds,
        private LoggingGateway $loggingGateway,
        private int $commitIntervalInMessages = 1,
    ) {
    }

    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
    {
        $consumer = $this->kafkaAdmin->getConsumer($this->endpointId);

        // Initialize coordinator on first use
        if ($this->batchCommitCoordinator === null || $this->batchCommitCoordinator->consumer !== $consumer) {
            $this->batchCommitCoordinator = new BatchCommitCoordinator(
                $this->commitIntervalInMessages,
                $consumer,
                $this->loggingGateway,
            );
        }

        $timeoutInMilliseconds = $pollingMetadata->getExecutionTimeLimitInMilliseconds() ?: $this->receiveTimeoutInMilliseconds;
        if ($timeoutInMilliseconds <= self::MINIMUM_REQUIRED_TIME_FOR_LOAD_BALANCING) {
            $timeoutInMilliseconds = self::MINIMUM_REQUIRED_TIME_FOR_LOAD_BALANCING;
        }
        $message = $consumer->consume($timeoutInMilliseconds);

        // RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN, RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS, RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS
        if (in_array($message->err, [RD_KAFKA_RESP_ERR__TIMED_OUT, RD_KAFKA_RESP_ERR__PARTITION_EOF,  RD_KAFKA_RESP_ERR__TRANSPORT])) {
            // This does happen when there is no topic, can't connect to broker, or simply consumer poll has reach time out
            return null;
        }

        if ($message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
            return $this->inboundMessageConverter->toMessage(
                $consumer,
                $message,
                $this->conversionService,
                $this->batchCommitCoordinator
            )->build();
        }

        if (in_array($message->err, [RD_KAFKA_MSG_PARTITIONER_RANDOM, RD_KAFKA_MSG_PARTITIONER_CONSISTENT, RD_KAFKA_MSG_PARTITIONER_CONSISTENT_RANDOM, RD_KAFKA_MSG_PARTITIONER_MURMUR2, RD_KAFKA_MSG_PARTITIONER_MURMUR2_RANDOM])) {
            $this->loggingGateway->info(
                sprintf(
                    '%s hashing key is used for related topic',
                    match ($message->err) {
                        RD_KAFKA_MSG_PARTITIONER_RANDOM => 'Random',
                        RD_KAFKA_MSG_PARTITIONER_CONSISTENT => 'Consistent',
                        RD_KAFKA_MSG_PARTITIONER_CONSISTENT_RANDOM => 'Consistent random',
                        RD_KAFKA_MSG_PARTITIONER_MURMUR2 => 'MurMur2',
                        RD_KAFKA_MSG_PARTITIONER_MURMUR2_RANDOM => 'MurMur2 random',
                        default => 'Unknown'
                    }
                )
            );

            return null;
        }

        throw MessagingException::create("Unhandled error code: {$message->err}");
    }

    public function onConsumerStop(): void
    {
        // Commit all pending messages before stopping
        if ($this->batchCommitCoordinator !== null) {
            $this->batchCommitCoordinator->forceCommitAll();
            $this->batchCommitCoordinator = null;
        }

        $this->kafkaAdmin->closeConsumer($this->endpointId);
    }
}
