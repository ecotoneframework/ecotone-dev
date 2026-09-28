<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\NamedEvent;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class DecisionModelEventMatchingTest extends TestCase
{
    public function test_model_handling_a_parent_event_folds_only_that_exact_class_not_its_subclasses(): void
    {
        $ecotone = $this->bootstrap();

        $ecotone->withEvents([new CourseEventForMatchingTest('course-1'), new SpecialCourseEventForMatchingTest('course-1')]);

        $this->assertSame(1, $ecotone->sendQueryWithRouting('matching.parentEvents', new CountCourseEventsForMatchingTest('course-1')));
    }

    public function test_model_folds_an_event_stored_under_its_named_event_name(): void
    {
        $ecotone = $this->bootstrap();

        $ecotone->withEvents([new NamedCourseEventForMatchingTest('course-1'), new NamedCourseEventForMatchingTest('course-1')]);

        $this->assertSame(2, $ecotone->sendQueryWithRouting('matching.namedEvents', new CountCourseEventsForMatchingTest('course-1')));
    }

    private function bootstrap(): FlowTestSupport
    {
        $counter = new CourseEventCounterForMatchingTest();

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$counter::class, ParentEventCountForMatchingTest::class, NamedEventCountForMatchingTest::class, CourseEventForMatchingTest::class, SpecialCourseEventForMatchingTest::class, NamedCourseEventForMatchingTest::class],
            containerOrAvailableServices: [$counter],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}

final readonly class CountCourseEventsForMatchingTest
{
    public function __construct(
        public string $courseId,
    ) {
    }
}

class CourseEventForMatchingTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
    ) {
    }
}

final class SpecialCourseEventForMatchingTest extends CourseEventForMatchingTest
{
}

#[NamedEvent('matching.course_event')]
final readonly class NamedCourseEventForMatchingTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
    ) {
    }
}

#[DecisionModel]
final class ParentEventCountForMatchingTest
{
    public int $count = 0;

    #[EventSourcingHandler]
    public function when(CourseEventForMatchingTest $event): void
    {
        $this->count++;
    }
}

#[DecisionModel]
final class NamedEventCountForMatchingTest
{
    public int $count = 0;

    #[EventSourcingHandler]
    public function when(NamedCourseEventForMatchingTest $event): void
    {
        $this->count++;
    }
}

final class CourseEventCounterForMatchingTest
{
    #[QueryHandler('matching.parentEvents')]
    public function parentEvents(CountCourseEventsForMatchingTest $query, ParentEventCountForMatchingTest $model): int
    {
        return $model->count;
    }

    #[QueryHandler('matching.namedEvents')]
    public function namedEvents(CountCourseEventsForMatchingTest $query, NamedEventCountForMatchingTest $model): int
    {
        return $model->count;
    }
}
