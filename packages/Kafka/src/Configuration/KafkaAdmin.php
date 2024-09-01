<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Configuration;

use Ecotone\Messaging\Config\ConfigurationException;

final class KafkaAdmin
{
    /**
     * @param ConsumerConfiguration[] $consumerConfigurations
     * @param TopicConfiguration[] $topicConfigurations
     */
    public function __construct(
        private array $consumerConfigurations,
        private array $topicConfigurations,
    )
    {

    }

    public function getConfigurationForConsumer(string $endpointId): ConsumerConfiguration
    {
        if (!array_key_exists($endpointId, $this->consumerConfigurations)) {
            return ConsumerConfiguration::createWithDefaults($endpointId);
        }

        return $this->consumerConfigurations[$endpointId];
    }

    public function getConfigurationForTopic(string $topicName): TopicConfiguration
    {
        if (!array_key_exists($topicName, $this->topicConfigurations)) {
            throw ConfigurationException::create(sprintf("No configuration for topic with name %s", $topicName));
        }

        return $this->topicConfigurations[$topicName];
    }
}