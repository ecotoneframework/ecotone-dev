<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Configuration;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\MessagePublisher;
use RdKafka\Conf;

/**
 * licence Enterprise
 */
final class KafkaPublisherConfiguration implements DefinedObject
{
    /**
     * @param array<string, string> $configuration
     */
    private function __construct(
        private string $defaultTopicName,
        private string $referenceName,
        private array  $configuration,
        private string $brokerConfigurationReference,
        private HeaderMapper $headerMapper,
        private ?string $outputDefaultConversionMediaType = null,
    ) {
    }

    public static function createWithDefaults(string $topicName = '', string $referenceName = MessagePublisher::class, string $brokerConfigurationReference = KafkaBrokerConfiguration::class): self
    {
        return new self(
            $topicName,
            $referenceName,
            [
                // By default in the absence of idempotence a producer may inadvertently publish a record in duplicate our of order - if one of the queued records experiences timeout
                'enable.idempotence' => 'true',
                // This configuration sets the maximum amount of time (in milliseconds) that the producer will wait for an acknowledgment from the broker before considering the message send to have failed.
                'message.timeout.ms' => '15000',
                // This configuration sets the maximum amount of time (in milliseconds) that the producer will wait for a response from the broker for a request
                'request.timeout.ms' => '15000',
                /**
                 * 0: The producer does not wait for any acknowledgment from the broker. This provides the lowest latency but the weakest durability guarantees (messages can be lost if the broker fails).
                 * 1: The producer waits for the leader to write the record to its local log only. This provides better durability than 0 but still risks data loss if the leader fails immediately after acknowledging the record.
                 * -1 (or all): The producer waits for the full set of in-sync replicas to acknowledge the record. This provides the strongest durability guarantees.
                 */
                'request.required.acks' => '-1',
                // This ensures more connections for the producer to send messages
                // five is maximum for idempotent producer
                'max.in.flight.requests.per.connection' => '5',
                // Enable given set of retries on producing failure
                'retries' => '5',
                // Backoff time between retries in milliseconds
                'retry.backoff.ms' => '100',
            ],
            $brokerConfigurationReference,
            DefaultHeaderMapper::createAllHeadersMapping()
        );
    }

    /**
     * @link https://github.com/confluentinc/librdkafka/blob/master/CONFIGURATION.md
     */
    public function setConfiguration(string $key, string $value): self
    {
        $this->configuration[$key] = $value;

        return $this;
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
        $this->headerMapper = DefaultHeaderMapper::createWith([], explode(',', $headerMapper));

        return $this;
    }

    public function getHeaderMapper(): HeaderMapper
    {
        return $this->headerMapper;
    }

    public function getOutputDefaultConversionMediaType(): ?string
    {
        return $this->outputDefaultConversionMediaType;
    }

    public function getBrokerConfigurationReference(): string
    {
        return $this->brokerConfigurationReference;
    }

    public function getAsKafkaConfig(): Conf
    {
        $conf = new Conf();
        foreach ($this->configuration as $key => $value) {
            $conf->set($key, $value);
        }

        return $conf;
    }

    public function getDefaultTopicName(): string
    {
        return $this->defaultTopicName;
    }

    public function getReferenceName(): string
    {
        return $this->referenceName;
    }

    public function getDefinition(): Definition
    {
        return Definition::createFor(static::class, [
            $this->defaultTopicName,
            $this->referenceName,
            $this->configuration,
            $this->brokerConfigurationReference,
            $this->headerMapper->getDefinition(),
            $this->outputDefaultConversionMediaType,
        ]);
    }
}
