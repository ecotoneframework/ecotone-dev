<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Configuration;

use Ecotone\Messaging\Config\ConfigurationException;
use RdKafka\Producer;
use RdKafka\ProducerTopic;
use RdKafka\TopicConf;

/**
 * licence Enterprise
 */
final class KafkaAdmin
{
    /**
     * @var Producer[]
     */
    private array $initializedProducers = [];

    /**
     * @param KafkaConsumerConfiguration[] $consumerConfigurations
     * @param TopicConfiguration[] $topicConfigurations
     * @param KafkaPublisherConfiguration[] $publisherConfigurations
     */
    public function __construct(
        private array $consumerConfigurations,
        private array $topicConfigurations,
        private array $publisherConfigurations,
    ) {

    }

    public static function createEmpty(): self
    {
        return new self([], [], []);
    }

    public function getConfigurationForConsumer(string $endpointId): KafkaConsumerConfiguration
    {
        if (! array_key_exists($endpointId, $this->consumerConfigurations)) {
            return KafkaConsumerConfiguration::createWithDefaults($endpointId);
        }

        return $this->consumerConfigurations[$endpointId];
    }

    public function getConfigurationForTopic(string $topicName): TopicConf
    {
        if (! array_key_exists($topicName, $this->topicConfigurations)) {
            return TopicConfiguration::createWithDefaults($topicName)->getConfig();
        }

        return $this->topicConfigurations[$topicName]->getConfig();
    }

    private function getConfigurationForPublisher(string $referenceName): KafkaPublisherConfiguration
    {
        return $this->publisherConfigurations[$referenceName] ?? throw ConfigurationException::create("Publisher configuration for {$referenceName} not found");
    }

    public function getProducer(string $referenceName, KafkaBrokerConfiguration $kafkaBrokerConfiguration): Producer
    {
        if (! array_key_exists($referenceName, $this->initializedProducers)) {
            $conf = $this->getConfigurationForPublisher($referenceName);
            $conf = $conf->getAsKafkaConfig();
            $conf->set('bootstrap.servers', implode(',', $kafkaBrokerConfiguration->getBootstrapServers()));
            $producer = new Producer($conf);

            $this->initializedProducers[$referenceName] = $producer;
        }

        return $this->initializedProducers[$referenceName];
    }

    public function getTopicForProducer(string $referenceName, KafkaBrokerConfiguration $kafkaBrokerConfiguration): ProducerTopic
    {
        $producer = $this->getProducer($referenceName, $kafkaBrokerConfiguration);
        $topicName = $this->getConfigurationForPublisher($referenceName)->getDefaultTopicName();

        return $producer->newTopic(
            $topicName,
            $this->getConfigurationForTopic($topicName)
        );
    }
}
