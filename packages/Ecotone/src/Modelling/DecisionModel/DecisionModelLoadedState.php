<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use function array_key_exists;
use function array_values;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Message;
use Ecotone\Modelling\DecisionModel\Snapshot\PendingDecisionModelSnapshot;

use function sprintf;

/**
 * licence Enterprise
 */
final class DecisionModelLoadedState
{
    public const HEADER_NAME = 'ecotone.modelling.decision_model.loaded_state';

    /**
     * @param array<string, object|null> $instancesByParameterName
     * @param PendingDecisionModelSnapshot[] $pendingSnapshots
     */
    public function __construct(
        private readonly array $instancesByParameterName,
        private readonly AppendCondition $appendCondition,
        private readonly array $pendingSnapshots = [],
        private readonly int $foldedEventCount = 0,
    ) {
    }

    /**
     * @return class-string[]
     */
    public function loadedModelClassNames(): array
    {
        $classNames = [];
        foreach ($this->instancesByParameterName as $instance) {
            if ($instance !== null) {
                $classNames[$instance::class] = $instance::class;
            }
        }

        return array_values($classNames);
    }

    /**
     * @return array<array{name: string, value: string, expectedVersion: int, decidedBy?: string[]}>
     */
    public function capturedTagVersions(): array
    {
        return $this->appendCondition->expectedTagVersions();
    }

    public function foldedEventCount(): int
    {
        return $this->foldedEventCount;
    }

    /**
     * @return PendingDecisionModelSnapshot[]
     */
    public static function pendingSnapshotsCarriedBy(Message $message): array
    {
        $state = $message->getHeaders()->containsKey(self::HEADER_NAME)
            ? $message->getHeaders()->get(self::HEADER_NAME)
            : null;

        return $state instanceof self ? $state->pendingSnapshots : [];
    }

    public static function appendConditionCarriedBy(Message $message): AppendCondition
    {
        return self::appendConditionIn($message->getHeaders()->headers());
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function appendConditionIn(array $metadata): AppendCondition
    {
        $state = $metadata[self::HEADER_NAME] ?? null;

        return $state instanceof self ? $state->appendCondition : AppendCondition::empty();
    }

    public static function carryInto(Message $message, array $metadata): array
    {
        if ($message->getHeaders()->containsKey(self::HEADER_NAME)) {
            $metadata[self::HEADER_NAME] = $message->getHeaders()->get(self::HEADER_NAME);
        }

        return $metadata;
    }

    public static function instanceCarriedBy(Message $message, string $parameterName): ?object
    {
        $state = $message->getHeaders()->containsKey(self::HEADER_NAME)
            ? $message->getHeaders()->get(self::HEADER_NAME)
            : null;

        if (! $state instanceof self || ! array_key_exists($parameterName, $state->instancesByParameterName)) {
            throw ConfigurationException::create(sprintf(
                "Decision model parameter '%s' was not loaded for this message. Decision models are loaded for #[CommandHandler], #[EventHandler] and #[QueryHandler] methods only.",
                $parameterName,
            ));
        }

        return $state->instancesByParameterName[$parameterName];
    }
}
