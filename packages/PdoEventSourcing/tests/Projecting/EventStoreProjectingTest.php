<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Ecotone\Modelling\Event;
use Ecotone\Projecting\ProjectingManager;
use Ecotone\Projecting\ProjectorExecutor;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Projecting\InMemoryProjectionStateStorage;
use Test\Ecotone\Projecting\InMemoryStreamSource;

class EventStoreProjectingTest extends TestCase
{
    protected function buildProjectingManager(ProjectorExecutor $projectorExecutor): ProjectingManager
    {
        $proophEventStore = $this->createMock(\Prooph\EventStore\EventStore::class);
        $projectionStateStorage = new InMemoryProjectionStateStorage();

        return new ProjectingManager(
            $projectionStateStorage,
            $projectorExecutor,
            $source,
            "projection-name",
            "global-partition-key",
        );
    }

}