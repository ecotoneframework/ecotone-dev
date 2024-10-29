<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Configuration;

use Ecotone\Messaging\Config\ConfigurationException;
use RdKafka\TopicConf;

/**
 * licence Enterprise
 */
final class KafkaAdmin
{
    /**
     * @param KafkaConsumerConfiguration[] $consumerConfigurations
     * @param TopicConfiguration[] $topicConfigurations
     */
    public function __construct(
        private array $consumerConfigurations,
        private array $topicConfigurations,
    )
    {

    }

    public static function createEmpty(): self
    {
        return new self([], []);
    }

    public function getConfigurationForConsumer(string $endpointId): KafkaConsumerConfiguration
    {
        if (!array_key_exists($endpointId, $this->consumerConfigurations)) {
            return KafkaConsumerConfiguration::createWithDefaults($endpointId);
        }

        return $this->consumerConfigurations[$endpointId];
    }

    public function getConfigurationForTopic(string $topicName): TopicConf
    {
        if (!array_key_exists($topicName, $this->topicConfigurations)) {
            return TopicConfiguration::createWithDefaults($topicName)->getConfig();
        }

        return $this->topicConfigurations[$topicName]->getConfig();
    }
}