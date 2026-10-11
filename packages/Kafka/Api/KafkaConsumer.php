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
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
#[Attribute]
final class KafkaConsumer extends MessageConsumer implements DefinedObject
{
    public function __construct(
        string $endpointId,
        private array|string $topics,
        private ?string $groupId = null,
        private FinalFailureStrategy $finalFailureStrategy = FinalFailureStrategy::STOP,
        private int $commitIntervalInMessages = 1,
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

    public function getFinalFailureStrategy(): FinalFailureStrategy
    {
        return $this->finalFailureStrategy;
    }

    public function getCommitIntervalInMessages(): int
    {
        return $this->commitIntervalInMessages;
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
