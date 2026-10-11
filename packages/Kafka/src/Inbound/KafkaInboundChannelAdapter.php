<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Inbound;

use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Messaging\MessagePoller;
use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\MessagingException;

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
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class KafkaInboundChannelAdapter implements MessagePoller
{
    public const MINIMUM_REQUIRED_TIME_FOR_LOAD_BALANCING = 10000;
    private ?BatchCommitCoordinator $batchCommitCoordinator = null;

    public function __construct(
        protected KafkaAdmin                 $kafkaAdmin,
        protected InboundMessageConverter    $inboundMessageConverter,
        protected ConversionService          $conversionService,
        protected int                       $receiveTimeoutInMilliseconds,
        private LoggingGateway $loggingGateway,
        private int $commitIntervalInMessages,
        public readonly string $channelName,
    ) {
    }

    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
    {
        $endpointId = $pollingMetadata->getEndpointId();
        $channelName = $pollingMetadata->getPolledChannelName() ?? $this->channelName;
        $consumer = $this->kafkaAdmin->getConsumer($endpointId, $channelName);

        if ($this->batchCommitCoordinator === null || $this->batchCommitCoordinator->consumer !== $consumer) {
            $this->batchCommitCoordinator = new BatchCommitCoordinator(
                $this->commitIntervalInMessages,
                $consumer,
                $this->loggingGateway,
                $endpointId,
            );
        }

        $timeoutInMilliseconds = $pollingMetadata->getExecutionTimeLimitInMilliseconds() > 0
            ? min($pollingMetadata->getExecutionTimeLimitInMilliseconds(), $this->receiveTimeoutInMilliseconds)
            : $this->receiveTimeoutInMilliseconds;
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
                $endpointId,
                $channelName,
                $consumer,
                $message,
                $this->conversionService,
                $this->batchCommitCoordinator
            )
                ->setHeader(MessageHeaders::INBOUND_REQUEST_CHANNEL, $channelName)
                ->build();
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
            $endpointId = $this->batchCommitCoordinator->getEndpointId();
            $this->batchCommitCoordinator->forceCommitAll();
            $this->batchCommitCoordinator = null;
            //            $this->kafkaAdmin->closeConsumer($endpointId);
        }
    }
}
