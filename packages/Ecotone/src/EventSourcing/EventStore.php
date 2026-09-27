<?php

namespace Ecotone\EventSourcing;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\EventSourcing\EventStore\MetadataMatcher;
use Ecotone\Modelling\Event;

/**
 * licence Apache-2.0
 */
interface EventStore
{
    /**
     * Registered service id for the raw store, bypassing the EventStore gateway wired under EventStore::class.
     */
    public const RAW_REFERENCE = 'ecotone.eventSourcing.eventStore.instance';

    /**
     * Creates new Stream with Metadata and appends events to it
     *
     * @param Event[]|object[]|array[] $streamEvents
     */
    public function create(string $streamName, array $streamEvents = [], array $streamMetadata = []): void;
    /**
     * Appends events to existing Stream, or creates one and then appends events if it does not exists
     *
     * @param Event[]|object[]|array[] $streamEvents
     */
    public function appendTo(string $streamName, array $streamEvents, ?AppendCondition $appendCondition = null): void;

    public function delete(string $streamName): void;

    public function hasStream(string $streamName): bool;

    /**
     * @return Event[]
     */
    public function load(
        string $streamName,
        int $fromNumber = 1,
        ?int $count = null,
        ?MetadataMatcher $metadataMatcher = null,
        bool $deserialize = true
    ): iterable;

    /**
     * @param string[] $eventNames
     * @return Event[]
     */
    public function loadAggregateEvents(
        string $streamName,
        ?string $aggregateType,
        string $aggregateId,
        int $fromVersion = 1,
        ?int $count = null,
        array $eventNames = [],
        bool $deserialize = true
    ): iterable;

    public function loadByCriteria(EventCriteria ...$criteria): LoadedEvents;
}
