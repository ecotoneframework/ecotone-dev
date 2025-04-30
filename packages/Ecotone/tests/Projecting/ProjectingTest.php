<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting;

use Ecotone\Modelling\Event;
use Ecotone\Projecting\ProjectingManager;
use Ecotone\Projecting\ProjectorExecutor;
use PHPUnit\Framework\TestCase;

class ProjectingTest extends TestCase
{
    protected function buildProjectingManager(ProjectorExecutor $projectorExecutor): ProjectingManager
    {
        $source = new InMemoryStreamSource();
        $source->append(
            Event::createWithType('an-event-type', ['id' => 1]),
            Event::createWithType('an-event-type', ['id' => 2]),
            Event::createWithType('an-event-type', ['id' => 3]),
        );
        $projectionStateStorage = new InMemoryProjectionStateStorage();

        return new ProjectingManager(
            $projectionStateStorage,
            $projectorExecutor,
            $source,
            "projection-name",
        );
    }

    public function testProjecting(): void
    {
        $projector = new InMemoryProjector();
        $projectingManager = $this->buildProjectingManager($projector);

        self::assertCount(0, $projector);

        $projectingManager->execute();
        self::assertCount(3, $projector);

        $projectingManager->execute();
        self::assertCount(3, $projector);
    }
}