<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Configuration;

use Ecotone\Kafka\Inbound\KafkaAcknowledgementCallback;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use RdKafka\Conf;

/**
 * licence Enterprise
 */
final class KafkaConsumerConfiguration implements DefinedObject
{
    public const DEFAULT_RECEIVE_TIMEOUT = 10000;

    /**
     * @param array<string, string> $configuration
     */
    public function __construct(
        private string $endpointId,
        private array $configuration,
        private string $brokerConfigurationReference,
        private HeaderMapper $headerMapper,
        private int $receiveTimeoutInMilliseconds = self::DEFAULT_RECEIVE_TIMEOUT,
    ) {

    }

    /**
     * @param array<string> $topics
     */
    public static function createWithDefaults(
        string $endpointId,
        string $brokerConfigurationReference = KafkaBrokerConfiguration::class
    ): self {
        return new self($endpointId, [
            /** Ecotone commit automatically after message is consumed */
            'enable.auto.commit' => 'false',
            /** Send message when reached end of topic */
            'enable.partition.eof' => 'true',
            /** Start from earliest available offset */
            'auto.offset.reset' => 'earliest',
            /** In PHP with rdkafka, heartbeats are managed by the underlying librdkafka library, which handles them in the background. Even though PHP is single-threaded, librdkafka uses its own internal threads to manage tasks like sending heartbeats to the Kafka broker. */
            'session.timeout.ms' => '30000',
        ], $brokerConfigurationReference, DefaultHeaderMapper::createAllHeadersMapping());
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

    /**
     * @link https://github.com/confluentinc/librdkafka/blob/master/CONFIGURATION.md
     */
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

    /**
     * @param string $headerMapper comma separated list of headers to be mapped.
     *                             (e.g. "\*" or "thing1*, thing2" or "*thing1")
     */
    public function withHeaderMapper(string $headerMapper): self
    {
        $this->headerMapper = DefaultHeaderMapper::createWith(explode(',', $headerMapper), []);

        return $this;
    }

    public function withReceiveTimeoutInMilliseconds(int $receiveTimeoutInMilliseconds): self
    {
        $this->receiveTimeoutInMilliseconds = $receiveTimeoutInMilliseconds;

        return $this;
    }

    public function getAcknowledgeMode(): string
    {
        /** Auto commit is autocommit in Kafka, then Ecotone should not commit offsets */
        return $this->configuration['enable.auto.commit'] === 'false'
            ? KafkaAcknowledgementCallback::AUTO_ACK
            : KafkaAcknowledgementCallback::NONE;
    }

    public function getBrokerConfigurationReference(): string
    {
        return $this->brokerConfigurationReference;
    }

    public function getEndpointId(): string
    {
        return $this->endpointId;
    }

    public function getHeaderMapper(): HeaderMapper
    {
        return $this->headerMapper;
    }

    public function getDefinition(): Definition
    {
        return Definition::createFor(static::class, [
            $this->endpointId,
            $this->configuration,
            $this->brokerConfigurationReference,
            $this->headerMapper->getDefinition(),
            $this->receiveTimeoutInMilliseconds,
        ]);
    }
}
