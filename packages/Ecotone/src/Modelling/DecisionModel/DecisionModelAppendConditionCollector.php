<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\EventSourcing\AppendCondition;

/**
 * licence Enterprise
 */
final class DecisionModelAppendConditionCollector
{
    /**
     * @var array<string, AppendCondition>
     */
    private array $conditionsByMessageId = [];

    public function record(string $messageId, AppendCondition $condition): void
    {
        $existing = $this->conditionsByMessageId[$messageId] ?? AppendCondition::empty();

        $this->conditionsByMessageId[$messageId] = $existing->mergeWith($condition);
    }

    public function consume(string $messageId): AppendCondition
    {
        $condition = $this->conditionsByMessageId[$messageId] ?? AppendCondition::empty();

        unset($this->conditionsByMessageId[$messageId]);

        return $condition;
    }
}
