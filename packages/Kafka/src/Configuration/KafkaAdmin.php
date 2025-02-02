<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Configuration;

use Ecotone\Kafka\Attribute\KafkaConsumer as KafkaConsumerAttribute;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Support\Assert;
use Exception;
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
     * @param array<string, string> $topicReferenceMapping
     * @param array<string, KafkaBrokerConfiguration> $kafkaBrokerConfigurations
     */
    public function __construct(
        private array $kafkaConsumers,
        private array $consumerConfigurations,
        private array $topicConfigurations,
        private array $publisherConfigurations,
        private array $kafkaBrokerConfigurations,
        private array $topicReferenceMapping,
        private LoggingGateway $loggingGateway,
        private bool $isRunningInTestMode
    ) {

    }

    public function getConfigurationForConsumer(string $endpointId): KafkaConsumerConfiguration
    {
        if (! array_key_exists($endpointId, $this->consumerConfigurations)) {
            return KafkaConsumerConfiguration::createWithDefaults($endpointId);
        }

        return $this->consumerConfigurations[$endpointId];
    }

    public function getConfigurationForTopic(string $referenceName): TopicConf
    {
        if (! array_key_exists($referenceName, $this->topicConfigurations)) {
            return TopicConfiguration::createWithDefaults($referenceName)->getConfig();
        }

        return $this->topicConfigurations[$referenceName]->getConfig();
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
            $kafkaBrokerConfiguration = $this->kafkaBrokerConfigurations[$configuration->getBrokerConfigurationReference()];
            $conf = $configuration->getConfig();
            $conf->set('group.id', $this->kafkaConsumers[$endpointId]->getGroupId());
            $conf->set('metadata.broker.list', implode(',', $kafkaBrokerConfiguration->getBootstrapServers()));
            $this->setLoggerCallbacks($conf, $endpointId);
            $consumer = new KafkaConsumer($conf);

            $topics = $this->getMappedTopicNames($this->kafkaConsumers[$endpointId]->getTopics());
            if ($this->isRunningForTests($kafkaBrokerConfiguration)) {
                // ensures there is no need for repartitioning
                $consumer->assign([new TopicPartition($topics[0], 0)]);
            } else {
                $consumer->subscribe($topics);
            }

            $this->initializedConsumers[$endpointId] = $consumer;
        }

        return $this->initializedConsumers[$endpointId];
    }

    public function closeConsumer(string $endpointId): void
    {
        if (! array_key_exists($endpointId, $this->initializedConsumers)) {
            return;
        }

        try {
            $this->initializedConsumers[$endpointId]->close();
        } catch (Exception) {

        } finally {
            unset($this->initializedConsumers[$endpointId]);
        }
    }

    public function getProducer(string $referenceName): Producer
    {
        if (! array_key_exists($referenceName, $this->initializedProducers)) {
            $configuration = $this->getConfigurationForPublisher($referenceName);
            $conf = $configuration->getAsKafkaConfig();
            $conf->set('metadata.broker.list', implode(',', $this->kafkaBrokerConfigurations[$configuration->getBrokerConfigurationReference()]->getBootstrapServers()));
            $this->setLoggerCallbacks($conf, $referenceName);
            $producer = new Producer($conf);
            $producer->addBrokers(implode(',', $this->kafkaBrokerConfigurations[$configuration->getBrokerConfigurationReference()]->getBootstrapServers()));

            $this->initializedProducers[$referenceName] = $producer;
        }

        return $this->initializedProducers[$referenceName];
    }

    public function getTopicForProducer(string $referenceName): ProducerTopic
    {
        $producer = $this->getProducer($referenceName);
        $topicName = $this->getMappedTopicNames($this->getConfigurationForPublisher($referenceName)->getDefaultTopicName());

        return $producer->newTopic(
            $topicName,
            $this->getConfigurationForTopic($topicName)
        );
    }

    private function isRunningForTests(KafkaBrokerConfiguration $kafkaBrokerConfiguration): bool
    {
        return ($this->isRunningInTestMode && $kafkaBrokerConfiguration->isSetupForTesting() === null) || $kafkaBrokerConfiguration->isSetupForTesting() === true;
    }

    private function getMappedTopicNames(string|array $topicName): string|array
    {
        if (is_array($topicName)) {
            return array_map(
                fn (string $topicName) => $this->getMappedTopicNames($topicName),
                $topicName
            );
        }

        return $this->topicReferenceMapping[$topicName] ?? $topicName;
    }

    private function setLoggerCallbacks(\RdKafka\Conf $conf, string $endpointId): void
    {
        $conf->setLogCb(
            function ($producerOrConsumer, int $level, string $facility, string $message) use ($endpointId): void {
                $this->loggingGateway->info("Kafka log in {$endpointId}: {$message}");
            }
        );
        $conf->setErrorCb(
            function ($producerOrConsumer, int $err, string $reason) use ($endpointId): void {
                $this->loggingGateway->error("Kafka error in {$endpointId}: {$reason}", ['error' => $err]);
            }
        );
    }
}
