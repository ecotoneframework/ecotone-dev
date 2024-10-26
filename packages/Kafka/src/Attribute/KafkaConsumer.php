<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Attribute;

#[\Attribute]
final class KafkaConsumer
{
    public function __construct(
        private string $endpointId,
        private array|string $topics
    )
    {

    }

    /**
     * @return string[]
     */
    public function getTopics(): array
    {
        return is_string($this->topics) ? [$this->topics] : $this->topics;
    }
}