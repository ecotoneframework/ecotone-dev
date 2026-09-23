<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionBoundary;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\TaggedEventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 */
final class DecisionBoundaryTest extends TestCase
{
    public function test_decision_boundary_scopes_the_append_condition_without_a_decision_model(): void
    {
        $handler = new RatingHandlerForBoundaryTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, CourseRatedForBoundaryTest::class],
            containerOrAvailableServices: [$handler],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommand(new RateCourseForBoundaryTest('course-1', 5));

        /** @var TaggedEventStore $taggedEventStore */
        $taggedEventStore = $ecotone->getServiceFromContainer(TaggedEventStore::class);
        $this->assertCount(1, $taggedEventStore->load(EventCriteria::tag('course', 'course-1'))->events);
    }
}

final readonly class RateCourseForBoundaryTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        public int $rating,
    ) {
    }
}

final readonly class CourseRatedForBoundaryTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        public int $rating,
    ) {
    }
}

final class RatingHandlerForBoundaryTest
{
    #[DecisionBoundary]
    public static function boundary(RateCourseForBoundaryTest $command): EventCriteria
    {
        return EventCriteria::tag('course', $command->courseId);
    }

    #[CommandHandler]
    public function rate(RateCourseForBoundaryTest $command): array
    {
        return [new CourseRatedForBoundaryTest($command->courseId, $command->rating)];
    }
}
