<?php

declare(strict_types=1);

namespace Ecotone\Api\Kafka;

use Attribute;
use Ecotone\Api\ExtensionObject\FinalFailureStrategy;
use Ecotone\Messaging\Attribute\MessageConsumer;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Support\Assert;

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
#[Attribute]
final class KafkaConsumer extends MessageConsumer implements DefinedObject
{
    public readonly string $groupId;

    public function __construct(
        string $endpointId,
        private array|string $topics,
        ?string $groupId = null,
        private FinalFailureStrategy $finalFailureStrategy = FinalFailureStrategy::STOP,
        public readonly int $commitIntervalInMessages = 1,
    ) {
        Assert::notNullAndEmpty($topics, "Topics can't be empty");

        parent::__construct($endpointId);

        $this->groupId = $groupId ?: $this->getEndpointId();
    }

    /**
     * @return string[]
     */
    public function getTopics(): array
    {
        return is_string($this->topics) ? [$this->topics] : $this->topics;
    }

    public function getFinalFailureStrategy(): FinalFailureStrategy
    {
        return $this->finalFailureStrategy;
    }

    public function getDefinition(): Definition
    {
        return new Definition(
            self::class,
            [
                $this->getEndpointId(),
                $this->topics,
                $this->groupId,
                $this->finalFailureStrategy,
                $this->commitIntervalInMessages,
            ]
        );
    }

    public function changeGroupId(string $groupId): self
    {
        return new self(
            $this->getEndpointId(),
            $this->topics,
            $groupId,
            $this->finalFailureStrategy,
            $this->commitIntervalInMessages,
        );
    }
}
