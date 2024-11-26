<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Attribute;

use Attribute;
use Ecotone\Messaging\Attribute\MessageConsumer;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Support\Assert;

/**
 * licence Enterprise
 */
#[Attribute]
final class KafkaConsumer extends MessageConsumer implements DefinedObject
{
    public function __construct(
        string $endpointId,
        private array|string $topics,
        private ?string $groupId = null
    ) {
        Assert::notNullAndEmpty($topics, "Topics can't be empty");

        parent::__construct($endpointId);

        if (! $this->groupId) {
            $this->groupId = $this->getEndpointId();
        }
    }

    /**
     * @return string[]
     */
    public function getTopics(): array
    {
        return is_string($this->topics) ? [$this->topics] : $this->topics;
    }

    public function getGroupId(): string
    {
        return $this->groupId;
    }

    public function getDefinition(): Definition
    {
        return new Definition(
            self::class,
            [
                $this->getEndpointId(),
                $this->topics,
                $this->groupId,
            ]
        );
    }
}
