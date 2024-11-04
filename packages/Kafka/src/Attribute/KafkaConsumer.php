<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Attribute;

use Attribute;
use Ecotone\Messaging\Attribute\MessageConsumer;

/**
 * licence Enterprise
 */
#[Attribute]
final class KafkaConsumer extends MessageConsumer
{
    public function __construct(
        string $endpointId,
        private array|string $topics,
        private ?string $groupId = null
    ) {
        parent::__construct($endpointId);
    }

    /**
     * @return string[]
     */
    public function getTopics(): array
    {
        return is_string($this->topics) ? [$this->topics] : $this->topics;
    }

    public function getGroupId(): ?string
    {
        return $this->groupId;
    }
}
