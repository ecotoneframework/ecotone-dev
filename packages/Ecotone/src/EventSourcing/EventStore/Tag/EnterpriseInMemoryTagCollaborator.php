<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\EventStore\Tag;

use function array_values;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\EventSourcing\EventStore\InMemoryEventStore;
use Ecotone\EventSourcing\Tagging\TagResolver;

/**
 * licence Enterprise
 */
final class EnterpriseInMemoryTagCollaborator implements InMemoryTagCollaborator
{
    private InMemoryTagVersionRegister $versions;

    private InMemoryTagIndex $index;

    public function __construct(
        private readonly TagResolver $tagResolver,
    ) {
        $this->versions = new InMemoryTagVersionRegister();
        $this->index = new InMemoryTagIndex();
    }

    public function loadByCriteria(InMemoryEventStore $eventStore, EventCriteria $criteria): LoadedEvents
    {
        $captured = $this->versions->capture($this->tagResolver->tagsOfCriteria($criteria));
        $events = $this->index->eventsMatching($eventStore, $criteria);

        return new LoadedEvents($events, AppendCondition::fromCapturedVersions(array_values($captured)));
    }

    public function appendEventsWithTagCondition(InMemoryEventStore $eventStore, string $streamName, array $events, ?AppendCondition $appendCondition): void
    {
        $appended = $this->tagResolver->resolveAppend($events, $appendCondition);
        $expected = $appended->expectedVersions($this->versions->capture($appended->needingCapture()));

        $this->versions->assertUnchanged($expected);
        $this->versions->bump($expected);

        $firstEventNo = $eventStore->nextEventNumber($streamName);
        $eventStore->appendEventsUnconditionally($streamName, $events);
        $this->index->record($streamName, $firstEventNo, $appended->sequencedAfterBump($expected));
    }

    public function deleteTagIndexFor(string $streamName): void
    {
        $this->index->deleteStream($streamName);
    }

    public function bumpTagsGuarded(AppendCondition $appendCondition): void
    {
        $appended = $this->tagResolver->resolveAppend([], $appendCondition);
        $expected = $appended->expectedVersions($this->versions->capture($appended->needingCapture()));

        $this->versions->assertUnchanged($expected);
        $this->versions->bump($expected);
    }
}
