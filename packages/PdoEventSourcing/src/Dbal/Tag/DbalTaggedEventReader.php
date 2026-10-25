<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use function array_values;
use function count;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\EventSourcing\Tagging\MatchedEvents;
use Ecotone\EventSourcing\Tagging\TagKey;
use Ecotone\EventSourcing\Tagging\TagResolver;

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
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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

        $captured = $this->readingIndex($eventStore, fn (): array => $this->versions->capture($connection, $this->tagResolver->countedTagsOfCriteria($criteria)));

        return new LoadedEvents(
            $criteria->limit === null ? $this->everyEventMatching($eventStore, $connection, $criteria, $tags) : $this->pageOfEventsMatching($eventStore, $connection, $criteria, $tags),
            AppendCondition::fromCapturedVersions(array_values($captured))
        );
    }

    /**
     * @param array<string, array{name: string, value: string}> $tags
     * @return Event[]
     */
    private function everyEventMatching(DbalEventStore $eventStore, Connection $connection, EventCriteria $criteria, array $tags): array
    {
        $flags = $this->readingIndex($eventStore, fn (): array => $this->index->flagsFor($connection, $tags, $this->tagResolver->pushDownableTagSequenceLowerBounds($criteria)));

        return $this->matchingEvents($criteria, $flags, $this->index->eventsReferencedBy($eventStore, $connection, $flags));
    }

    /**
     * @param array<string, array{name: string, value: string}> $tags
     * @return Event[]
     */
    private function pageOfEventsMatching(DbalEventStore $eventStore, Connection $connection, EventCriteria $criteria, array $tags): array
    {
        $positionTag = $this->tagResolver->positionTagOf($criteria);
        $positionTagKey = TagKey::of($positionTag['name'], $positionTag['value']);
        $afterTagSequence = $criteria->tagSequenceLowerBound;
        $events = [];

        do {
            $sequences = $this->readingIndex($eventStore, fn (): array => $this->index->sequencesOfTagAfter($connection, $positionTag, $afterTagSequence, $criteria->limit));
            if ($sequences === []) {
                break;
            }

            $upToTagSequence = $sequences[count($sequences) - 1];
            $flags = $this->readingIndex($eventStore, fn (): array => $this->index->flagsWithinSequences($connection, $tags, $positionTagKey, $afterTagSequence, $upToTagSequence));
            $events = [...$events, ...$this->matchingEvents($criteria, $flags, $this->index->eventsReferencedBy($eventStore, $connection, $flags))];
            $afterTagSequence = $upToTagSequence;
        } while (count($events) < $criteria->limit && count($sequences) === $criteria->limit);

        return $this->tagResolver->pageOf($events, $criteria);
    }

    /**
     * @template T
     * @param callable(): T $read
     * @return T
     */
    private function readingIndex(DbalEventStore $eventStore, callable $read): mixed
    {
        try {
            return $read();
        } catch (TableNotFoundException) {
            throw $this->tables->missingTablesException($eventStore);
        }
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
            if ($branch->tags === []) {
                continue;
            }

            $positionTag = $this->tagResolver->positionTagOf($branch);
            $positionTagKey = TagKey::of($positionTag['name'], $positionTag['value']);

            foreach ($flags as $flag) {
                $event = $eventsByStream[$flag['stream']][$flag['eventNo']] ?? null;
                $sequence = $flag['sequences'][$positionTagKey] ?? null;

                if ($event === null || $sequence === null || ($branch->tagSequenceLowerBound > 0 && $sequence <= $branch->tagSequenceLowerBound)) {
                    continue;
                }

                if (! $this->flagCarriesAllTags($flag, $branch) || ! $branch->matchesEventType($event->eventName)) {
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
        foreach ($branch->tags as $tag) {
            if (! ($flag['has'][TagKey::of($tag['name'], $tag['value'])] ?? false)) {
                return false;
            }
        }

        return true;
    }
}
