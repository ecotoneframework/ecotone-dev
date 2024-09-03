<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Configuration;

use Ecotone\Kafka\Inbound\KafkaAcknowledgementCallback;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use RdKafka\Conf;

final class ConsumerConfiguration implements DefinedObject
{
    /**
     * @param array<string, string> $configuration
     */
    private function __construct(
        private string $endpointId,
        private array $configuration,
        private string $brokerConfigurationReference
    )
    {

    }

    public static function createWithDefaults(string $endpointId, string $brokerConfigurationReference = KafkaBrokerConfiguration::class): self
    {
        return new self($endpointId, [
            'group.id' => $endpointId,
            /** Ecotone commit automatically after message is consumed */
            'enable.auto.commit' => 'false',
            /** Send message when reached end of topic */
            'enable.partition.eof' => 'true',
            /** Start from earliest available offset */
            'auto.offset.reset' => 'earliest',
            /** In PHP with rdkafka, heartbeats are managed by the underlying librdkafka library, which handles them in the background. Even though PHP is single-threaded, librdkafka uses its own internal threads to manage tasks like sending heartbeats to the Kafka broker. */
            'session.timeout.ms' => '30000',
        ], $brokerConfigurationReference);
    }

    /**
     * By default, the consumer will automatically commit offsets.
     */
    public function enableAutoCommit(string $intervalMilliseconds = '100'): self
    {
        $this->configuration['enable.auto.commit'] = 'true';
        $this->configuration['auto.commit.interval.ms'] = $intervalMilliseconds;

        return $this;
    }

    public function set(string $key, string $value): self
    {
        $this->configuration[$key] = $value;

        return $this;
    }

    public function getConfig(): Conf
    {
        $conf = new Conf();
        foreach ($this->configuration as $key => $value) {
            $conf->set($key, $value);
        }

        return $conf;
    }

    public function enableKafkaDebugging(): self
    {
        $this->configuration['log_level'] = (string) LOG_DEBUG;
        $this->configuration['debug'] = 'all';

        return $this;
    }

    public function getAcknowledgeMode(): string
    {
        /** Auto commit is autocommit in Kafka, where from Ecotone perspective it's manual ack */
        return $this->configuration['enable.auto.commit'] === 'false'
            ? KafkaAcknowledgementCallback::AUTO_ACK
            : KafkaAcknowledgementCallback::MANUAL_ACK;
    }

    public function getEndpointId(): string
    {
        return $this->endpointId;
    }

    public function getDefinition(): Definition
    {
        return Definition::createFor(static::class, [
            $this->endpointId,
            $this->configuration
        ]);
    }
}