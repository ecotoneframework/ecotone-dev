<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Inbound;

use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Kafka\Configuration\KafkaConsumerConfiguration;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessagePoller;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\TopicConf;

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

        var_dump($message);
        if ($message->err) {
            return null;
        }

        return $this->inboundMessageConverter->toMessage($consumer, $message, $this->conversionService)
                    ->build();
    }
}
