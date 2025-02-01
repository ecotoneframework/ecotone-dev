<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Inbound;

use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessagePoller;
use Ecotone\Messaging\MessagingException;

/**
 * licence Enterprise
 */
final class KafkaInboundChannelAdapter implements MessagePoller
{
    public function __construct(
        private string                     $endpointId,
        protected KafkaAdmin                 $kafkaAdmin,
        protected InboundMessageConverter    $inboundMessageConverter,
        protected ConversionService          $conversionService,
        protected int                       $receiveTimeoutInMilliseconds,
    ) {
    }

    public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
    {
        $consumer = $this->kafkaAdmin->getConsumer($this->endpointId);

        $message = $consumer->consume($timeoutInMilliseconds ?: $this->receiveTimeoutInMilliseconds);

        // RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN, RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS, RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS
        if (in_array($message->err, [RD_KAFKA_RESP_ERR__TIMED_OUT, RD_KAFKA_RESP_ERR__PARTITION_EOF,  RD_KAFKA_RESP_ERR__TRANSPORT])) {
            // This does happen when there is no topic, can't connect to broker, or simply consumer poll has reach time out
            return null;
        }

        if ($message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
            return $this->inboundMessageConverter->toMessage($consumer, $message, $this->conversionService)
                ->build();
        }

        throw MessagingException::create("Unhandled error code: {$message->err}");
    }
}
