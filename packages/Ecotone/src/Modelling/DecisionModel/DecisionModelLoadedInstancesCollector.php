<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

/**
 * licence Enterprise
 */
final class DecisionModelLoadedInstancesCollector
{
    /**
     * @var array<string, array<string, object|null>>
     */
    private array $instancesByMessageId = [];

    public function hasBatchFor(string $messageId): bool
    {
        return array_key_exists($messageId, $this->instancesByMessageId);
    }

    /**
     * @param array<string, object|null> $instancesByParameterName
     */
    public function record(string $messageId, array $instancesByParameterName): void
    {
        $this->instancesByMessageId[$messageId] = $instancesByParameterName;
    }

    public function consume(string $messageId, string $parameterName): ?object
    {
        $instance = $this->instancesByMessageId[$messageId][$parameterName] ?? null;

        unset($this->instancesByMessageId[$messageId][$parameterName]);
        if (($this->instancesByMessageId[$messageId] ?? null) === []) {
            unset($this->instancesByMessageId[$messageId]);
        }

        return $instance;
    }
}
