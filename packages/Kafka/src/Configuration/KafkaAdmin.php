<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Configuration;

use Ecotone\Kafka\Attribute\KafkaConsumer as KafkaConsumerAttribute;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Exception;
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
     * @param KafkaConsumerAttribute[] $consumerConfigurations
     * @param KafkaConsumerConfiguration[] $rdKafkaConsumerConfigurations
     * @param TopicConfiguration[] $topicConfigurations
     * @param KafkaPublisherConfiguration[] $publisherConfigurations
     * @param array<string, string> $topicReferenceMapping
     * @param array<string, KafkaBrokerConfiguration> $kafkaBrokerConfigurations
     */
    public function __construct(
        private array          $consumerConfigurations,
        private array          $rdKafkaConsumerConfigurations,
        private array          $topicConfigurations,
        private array          $publisherConfigurations,
        private array          $kafkaBrokerConfigurations,
        private array          $topicReferenceMapping,
        private LoggingGateway $loggingGateway,
    ) {
    }

    public function getRdKafkaConfiguration(string $channelName): KafkaConsumerConfiguration
    {
        if (! array_key_exists($channelName, $this->rdKafkaConsumerConfigurations)) {
            return KafkaConsumerConfiguration::createWithDefaults($channelName);
        }

        return $this->rdKafkaConsumerConfigurations[$channelName];
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

    public function getConsumer(string $endpointId, string $channelName): KafkaConsumer
    {
        if (! array_key_exists($endpointId, $this->initializedConsumers)) {
            $configuration = $this->getRdKafkaConfiguration($channelName);
            $kafkaBrokerConfiguration = $this->kafkaBrokerConfigurations[$configuration->getBrokerConfigurationReference()];
            $conf = $configuration->getConfig();
            $kafkaConsumerConfig = $this->getConsumerConfiguration($endpointId, $channelName);

            $conf->set('group.id', $kafkaConsumerConfig->getGroupId());
            $conf->set('metadata.broker.list', implode(',', $kafkaBrokerConfiguration->getBootstrapServers()));
            $this->setLoggerCallbacks($conf, $endpointId);
            $consumer = new KafkaConsumer($conf);

            $topics = $this->getMappedTopicNames($kafkaConsumerConfig->getTopics());
            $consumer->subscribe($topics);

            $this->initializedConsumers[$endpointId] = $consumer;
        }

        return $this->initializedConsumers[$endpointId];
    }

    public function closeAllConsumers(): void
    {
        foreach ($this->initializedConsumers as $endpointId => $consumer) {
            $this->closeConsumer($endpointId);
        }
    }

    public function closeConsumer(string $endpointId): void
    {
        if (! array_key_exists($endpointId, $this->initializedConsumers)) {
            return;
        }

        try {
            $this->initializedConsumers[$endpointId]->close();
        } catch (Exception $exception) {
            $this->loggingGateway->info('Failed to close consumer: ' . $exception->getMessage(), ['exception' => $exception]);
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

    public function getConsumerConfiguration(string $endpointId, string $channelName): KafkaConsumerAttribute
    {
        if (array_key_exists($channelName, $this->consumerConfigurations)) {
            $defaultChannelConfig = $this->consumerConfigurations[$channelName];

            if ($this->isUsedAsInputChannelForDifferentEndpoint($defaultChannelConfig, $endpointId)) {
                return $defaultChannelConfig->changeGroupId($defaultChannelConfig->getGroupId() . '_' . $endpointId);
            }

            return $defaultChannelConfig;
        }

        throw ConfigurationException::create("Consumer configuration for {$channelName} not found");
    }

    public function isUsedAsInputChannelForDifferentEndpoint(KafkaConsumerAttribute $defaultChannelConfig, string $endpointId): bool
    {
        return $defaultChannelConfig->getEndpointId() !== $endpointId;
    }
}
