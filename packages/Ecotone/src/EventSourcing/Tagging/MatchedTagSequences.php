<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

use Ecotone\Api\EventSourcing\Event;

/**
 * licence Enterprise
 */
final class MatchedTagSequences
{
    public const HEADER_NAME = 'ecotone.eventSourcing.tagging.matched_tag_sequences';

    /**
     * @param array<string, int> $sequencesByTagKey
     */
    public static function stampOn(Event $event, array $sequencesByTagKey): Event
    {
        return $sequencesByTagKey === [] ? $event : $event->withAddedMetadata([self::HEADER_NAME => $sequencesByTagKey]);
    }

    public static function of(Event $event, string $tagKey): ?int
    {
        $sequences = $event->getMetadata()[self::HEADER_NAME] ?? [];

        return $sequences[$tagKey] ?? null;
    }
}
