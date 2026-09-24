<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\Api\EventSourcing\TaggedEventStore;

/**
 * licence Enterprise
 */
final class DbalTaggedEventStore implements TaggedEventStore
{
    public function __construct(
        private readonly DbalEventStore $dbalEventStore,
    ) {
    }

    public function load(EventCriteria ...$criteria): LoadedEvents
    {
        return $this->dbalEventStore->loadByCriteria(...$criteria);
    }

    public function appendTo(string $streamName, array $events, ?AppendCondition $appendCondition = null): void
    {
        $this->dbalEventStore->appendTo($streamName, $events, $appendCondition);
    }
}
