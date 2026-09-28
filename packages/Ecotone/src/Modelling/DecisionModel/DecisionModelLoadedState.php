<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use function array_key_exists;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Message;

use function sprintf;

/**
 * licence Enterprise
 */
final class DecisionModelLoadedState
{
    public const HEADER_NAME = 'ecotone.modelling.decision_model.loaded_state';

    /**
     * @param array<string, object|null> $instancesByParameterName
     */
    public function __construct(
        private readonly array $instancesByParameterName,
        private readonly AppendCondition $appendCondition,
    ) {
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
