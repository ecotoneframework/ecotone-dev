<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Ecotone\Projecting\InMemory\InMemoryProjectionStateStorage;
use Ecotone\Projecting\ProjectingManager;
use Ecotone\Projecting\ProjectorExecutor;
use PHPUnit\Framework\TestCase;

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