<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use function array_values;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\EventSourcing\Tagging\MatchedEvents;
use Ecotone\EventSourcing\Tagging\TagKey;
use Ecotone\EventSourcing\Tagging\TagResolver;
use Ecotone\Modelling\Event;

/**
 * licence Enterprise
 */
final class DbalTaggedEventReader
{
    public function __construct(
        private readonly TagResolver $tagResolver,
        private readonly DbalTagTables $tables,
        private readonly DbalTagVersionRegister $versions,
        private readonly DbalTagIndex $index,
    ) {
    }

    public function loadByCriteria(DbalEventStore $eventStore, Connection $connection, EventCriteria $criteria): LoadedEvents
    {
        $tags = $this->tagResolver->tagsOfCriteria($criteria);
        if ($tags === []) {
            return new LoadedEvents([], AppendCondition::empty());
        }

        try {
            $captured = $this->versions->capture($connection, $tags);
            $flags = $this->index->flagsFor($connection, $tags, $this->tagResolver->pushDownableTagSequenceLowerBounds($criteria));
        } catch (TableNotFoundException) {
            throw $this->tables->missingTablesException($eventStore, $connection);
        }

        $eventsByStream = $this->index->eventsReferencedBy($eventStore, $connection, $flags);

        return new LoadedEvents(
            $this->matchingEvents($criteria, $flags, $eventsByStream),
            AppendCondition::fromCapturedVersions(array_values($captured))
        );
    }

    /**
     * @param array<array{stream: string, eventNo: int, has: array<string, bool>, sequences: array<string, ?int>}> $flags
     * @param array<string, array<int, Event>> $eventsByStream
     * @return Event[]
     */
    private function matchingEvents(EventCriteria $criteria, array $flags, array $eventsByStream): array
    {
        $matched = new MatchedEvents();

        foreach ($criteria->branches() as $branch) {
            if ($branch->tags() === []) {
                continue;
            }

            $positionTag = $this->tagResolver->positionTagOf($branch);
            $positionTagKey = TagKey::of($positionTag['name'], $positionTag['value']);

            foreach ($flags as $flag) {
                $event = $eventsByStream[$flag['stream']][$flag['eventNo']] ?? null;
                $sequence = $flag['sequences'][$positionTagKey] ?? null;

                if ($event === null || $sequence === null || ($branch->tagSequenceLowerBound() > 0 && $sequence <= $branch->tagSequenceLowerBound())) {
                    continue;
                }

                if (! $this->flagCarriesAllTags($flag, $branch) || ! $branch->matchesEventType($event->getEventName())) {
                    continue;
                }

                $matched->consider($flag['stream'], $flag['eventNo'], $sequence, $event, self::knownSequencesOf($flag));
            }
        }

        return $matched->inSequenceOrder();
    }

    /**
     * @param array{sequences: array<string, ?int>} $flag
     * @return array<string, int>
     */
    private static function knownSequencesOf(array $flag): array
    {
        $known = [];
        foreach ($flag['sequences'] as $tagKey => $sequence) {
            if ($sequence !== null) {
                $known[$tagKey] = $sequence;
            }
        }

        return $known;
    }

    /**
     * @param array{has: array<string, bool>} $flag
     */
    private function flagCarriesAllTags(array $flag, EventCriteria $branch): bool
    {
        foreach ($branch->tags() as $tag) {
            if (! ($flag['has'][TagKey::of($tag['name'], $tag['value'])] ?? false)) {
                return false;
            }
        }

        return true;
    }
}
