<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Inbound;

use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessagePoller;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\TopicConf;

final class KafkaInboundChannelAdapter implements MessagePoller
{
    protected Conf                    $configuration;

    /**
     * @param string[] $topicsToSubscribe
     * @param TopicConf[] $declaredTopicsOnStartup
     */
    public function __construct(
        private   string                  $endpointId,
        protected KafkaAdmin              $kafkaAdmin,
        protected array                   $topicsToSubscribe,
        protected int                     $receiveTimeoutInMilliseconds,
        protected InboundMessageConverter $inboundMessageConverter,
        protected ConversionService       $conversionService,
    ) {
    }

    public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
    {
        $consumer = new KafkaConsumer($this->kafkaAdmin->getConfigurationForConsumer($this->endpointId)->getConfig());

//        @TODO KafkaConsumer reuse it and verify connection

        $consumer->subscribe($this->topicsToSubscribe);

        $message = $consumer->consume($timeoutInMilliseconds);

        if ($message->err) {
            return null;
        }

        return $this->inboundMessageConverter->toMessage($consumer, $message, $this->conversionService)
                    ->build();
    }
}