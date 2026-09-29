<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

use Ecotone\Modelling\Event;

/**
 * The tag sequences an event was matched by, carried on the event while it travels from the
 * store to the fold. It is Ecotone's own bookkeeping: it never reaches persisted event
 * metadata and never reaches a published event.
 *
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
