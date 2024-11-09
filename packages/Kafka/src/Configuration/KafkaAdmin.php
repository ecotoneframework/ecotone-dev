<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Configuration;

use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Kafka\Attribute\KafkaConsumer as KafkaConsumerAttribute;
use Ecotone\Messaging\Support\Assert;
use RdKafka\KafkaConsumer;
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
     * @var KafkaConsumer[]
     */
    private array $initializedConsumers = [];

    /**
     * @param KafkaConsumerAttribute[] $kafkaConsumers
     * @param KafkaConsumerConfiguration[] $consumerConfigurations
     * @param TopicConfiguration[] $topicConfigurations
     * @param KafkaPublisherConfiguration[] $publisherConfigurations
     * @param array<string, KafkaBrokerConfiguration> $kafkaBrokerConfigurations
     */
    public function __construct(
        private array $kafkaConsumers,
        private array $consumerConfigurations,
        private array $topicConfigurations,
        private array $publisherConfigurations,
        private array $kafkaBrokerConfigurations = [],
    ) {

    }

    public static function createEmpty(): self
    {
        return new self([], [], [], []);
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

    public function getConfigurationForPublisher(string $referenceName): KafkaPublisherConfiguration
    {
        return $this->publisherConfigurations[$referenceName] ?? throw ConfigurationException::create("Publisher configuration for {$referenceName} not found");
    }

    public function getConsumer(string $endpointId): KafkaConsumer
    {
        if (! array_key_exists($endpointId, $this->initializedConsumers)) {
            Assert::keyExists($this->kafkaConsumers, $endpointId, "Consumer with endpoint id {$endpointId} not found");

            $configuration = $this->getConfigurationForConsumer($endpointId);
            $conf = $configuration->getConfig();
            $conf->set('group.id', $this->kafkaConsumers[$endpointId]->getGroupId());
            $conf->set('bootstrap.servers', implode(',', $this->kafkaBrokerConfigurations[$configuration->getBrokerConfigurationReference()]->getBootstrapServers()));
            $consumer = new KafkaConsumer($conf);

            $consumer->subscribe($this->kafkaConsumers[$endpointId]->getTopics());

            $this->initializedConsumers[$endpointId] = $consumer;
        }

        return $this->initializedConsumers[$endpointId];
    }

    public function getProducer(string $referenceName): Producer
    {
        if (! array_key_exists($referenceName, $this->initializedProducers)) {
            $configuration = $this->getConfigurationForPublisher($referenceName);
            $conf = $configuration->getAsKafkaConfig();
            $conf->set('bootstrap.servers', implode(',', $this->kafkaBrokerConfigurations[$configuration->getBrokerConfigurationReference()]->getBootstrapServers()));
            $producer = new Producer($conf);

            $this->initializedProducers[$referenceName] = $producer;
        }

        return $this->initializedProducers[$referenceName];
    }

    public function getTopicForProducer(string $referenceName): ProducerTopic
    {
        $producer = $this->getProducer($referenceName);
        $topicName = $this->getConfigurationForPublisher($referenceName)->getDefaultTopicName();

        return $producer->newTopic(
            $topicName,
            $this->getConfigurationForTopic($topicName)
        );
    }
}
