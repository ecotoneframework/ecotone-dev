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
    protected Conf                    $configuration;

    /**
     * @param string[] $topicsToSubscribe
     * @param TopicConf[] $declaredTopicsOnStartup
     */
    public function __construct(
        private string                     $endpointId,
        protected array                      $topicsToSubscribe,
        private string $groupId,
        protected KafkaAdmin                 $kafkaAdmin,
        protected KafkaConsumerConfiguration $kafkaConsumerConfiguration,
        private KafkaBrokerConfiguration $kafkaBrokerConfiguration,
        protected InboundMessageConverter    $inboundMessageConverter,
        protected ConversionService          $conversionService,
    ) {
    }

    public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
    {
        $conf = $this->kafkaConsumerConfiguration->getConfig();
        $conf->set('group.id', $this->groupId);
        $conf->set('bootstrap.servers', implode(',', $this->kafkaBrokerConfiguration->getBootstrapServers()));
        $consumer = new KafkaConsumer($conf);

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
