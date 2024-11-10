<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Configuration;

use Ecotone\Kafka\Attribute\KafkaConsumer as KafkaConsumerAttribute;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\Assert;
use RdKafka\KafkaConsumer;
use RdKafka\Producer;
use RdKafka\ProducerTopic;
use RdKafka\TopicConf;
use RdKafka\TopicPartition;

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
        private array $kafkaBrokerConfigurations,
        private bool $isRunningInTestMode
    ) {

    }

    public static function createEmpty(): self
    {
        return new self([], [], [], [], [], false);
    }

    public function getConfigurationForConsumer(string $endpointId): KafkaConsumerConfiguration
    {
        if (! array_key_exists($endpointId, $this->consumerConfigurations)) {
            return KafkaConsumerConfiguration::createWithDefaults($endpointId)->enableKafkaDebugging();
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
            $kafkaBrokerConfiguration = $this->kafkaBrokerConfigurations[$configuration->getBrokerConfigurationReference()];
            $conf->set('bootstrap.servers', implode(',', $kafkaBrokerConfiguration->getBootstrapServers()));
            $consumer = new KafkaConsumer($conf);

            if ($this->isRunningForTests($kafkaBrokerConfiguration)) {
                // ensures there is no need for repartitioning
                $consumer->assign([new TopicPartition($this->kafkaConsumers[$endpointId]->getTopics()[0], 0)]);
            } else {
                $consumer->subscribe($this->kafkaConsumers[$endpointId]->getTopics());
            }

            foreach ($this->kafkaConsumers[$endpointId]->getTopics() as $topic) {
                $consumer->subscribe([$topic]);
            }
            $consumer->assign([new TopicPartition($this->kafkaConsumers[$endpointId]->getTopics()[0], 0)]);

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

    private function isRunningForTests(KafkaBrokerConfiguration $kafkaBrokerConfiguration): bool
    {
        return ($this->isRunningInTestMode && $kafkaBrokerConfiguration->isSetupForTesting() === null) || $kafkaBrokerConfiguration->isSetupForTesting() === true;
    }
}
