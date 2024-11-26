<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Inbound;

use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessagePoller;

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
    ) {
    }

    public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
    {
        $consumer = $this->kafkaAdmin->getConsumer($this->endpointId);

        $message = $consumer->consume($timeoutInMilliseconds);

        if ($message->err == RD_KAFKA_RESP_ERR__TIMED_OUT) {
            // This does happen when there is no topic, can't connect to broker, or simply consumer poll has reach time out
            return null;
        }

        if ($message->err) {
            return null;
        }

        return $this->inboundMessageConverter->toMessage($consumer, $message, $this->conversionService)
                    ->build();
    }
}
