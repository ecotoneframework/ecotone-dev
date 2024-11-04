<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Configuration;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use RdKafka\TopicConf;

/**
 * licence Enterprise
 */
final class TopicConfiguration implements DefinedObject
{
    /**
     * @param array<string, string> $configuration
     */
    public function __construct(
        private string $topicName,
        private array  $configuration,
    ) {

    }

    public static function createWithDefaults(string $topicName): self
    {
        return new self(
            $topicName,
            [

            ]
        );
    }

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

    public function getConfig(): TopicConf
    {
        $conf = new TopicConf();
        foreach ($this->configuration as $key => $value) {
            $conf->set($key, $value);
        }

        return $conf;
    }

    public function getTopicName(): string
    {
        return $this->topicName;
    }

    public function getDefinition(): Definition
    {
        return Definition::createFor(static::class, [
            $this->topicName,
            $this->configuration,
        ]);
    }
}
