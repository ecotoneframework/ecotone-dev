<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionBoundary;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class DecisionBoundaryTest extends TestCase
{
    public function test_decision_boundary_scopes_the_append_condition_without_a_decision_model(): void
    {
        $handler = new RatingHandlerForBoundaryTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, CourseRatedForBoundaryTest::class],
            containerOrAvailableServices: [$handler],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommand(new RateCourseForBoundaryTest('course-1', 5));

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getServiceFromContainer(EventStore::class);
        $this->assertCount(1, $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'))->events);
    }

    public function test_non_static_decision_boundary_is_rejected_at_bootstrap(): void
    {
        $this->assertBootstrapRejects(new NonStaticBoundaryHandlerForBoundaryTest(), 'static');
    }

    public function test_decision_boundary_not_declaring_event_criteria_as_its_return_type_is_rejected_at_bootstrap(): void
    {
        $this->assertBootstrapRejects(new WrongReturnTypeBoundaryHandlerForBoundaryTest(), EventCriteria::class);
    }

    public function test_decision_boundary_without_a_command_parameter_is_rejected_at_bootstrap(): void
    {
        $this->assertBootstrapRejects(new ParameterlessBoundaryHandlerForBoundaryTest(), 'first parameter');
    }

    public function test_decision_boundary_matching_no_handler_of_its_class_is_rejected_at_bootstrap(): void
    {
        $this->assertBootstrapRejects(new OrphanBoundaryHandlerForBoundaryTest(), CourseRatedForBoundaryTest::class);
    }

    public function test_two_decision_boundaries_for_the_same_command_are_rejected_at_bootstrap(): void
    {
        $this->assertBootstrapRejects(new DuplicateBoundaryHandlerForBoundaryTest(), 'secondBoundary');
    }

    private function assertBootstrapRejects(object $handler, string $expectedMessagePart): void
    {
        try {
            EcotoneLite::bootstrapFlowTesting(
                classesToResolve: [$handler::class, CourseRatedForBoundaryTest::class],
                containerOrAvailableServices: [$handler],
                configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
                licenceKey: LicenceTesting::VALID_LICENCE,
            );
            $this->fail('Expected a ConfigurationException');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString($handler::class, $exception->getMessage());
            $this->assertStringContainsString($expectedMessagePart, $exception->getMessage());
        }
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

final class NonStaticBoundaryHandlerForBoundaryTest
{
    #[DecisionBoundary]
    public function boundary(RateCourseForBoundaryTest $command): EventCriteria
    {
        return EventCriteria::tag('course', $command->courseId);
    }

    #[CommandHandler]
    public function rate(RateCourseForBoundaryTest $command): array
    {
        return [];
    }
}

final class WrongReturnTypeBoundaryHandlerForBoundaryTest
{
    #[DecisionBoundary]
    public static function boundary(RateCourseForBoundaryTest $command): string
    {
        return $command->courseId;
    }

    #[CommandHandler]
    public function rate(RateCourseForBoundaryTest $command): array
    {
        return [];
    }
}

final class ParameterlessBoundaryHandlerForBoundaryTest
{
    #[DecisionBoundary]
    public static function boundary(): EventCriteria
    {
        return EventCriteria::tag('course', 'course-1');
    }

    #[CommandHandler]
    public function rate(RateCourseForBoundaryTest $command): array
    {
        return [];
    }
}

final class OrphanBoundaryHandlerForBoundaryTest
{
    #[DecisionBoundary]
    public static function boundary(CourseRatedForBoundaryTest $event): EventCriteria
    {
        return EventCriteria::tag('course', $event->courseId);
    }

    #[CommandHandler]
    public function rate(RateCourseForBoundaryTest $command): array
    {
        return [];
    }
}

final class DuplicateBoundaryHandlerForBoundaryTest
{
    #[DecisionBoundary]
    public static function firstBoundary(RateCourseForBoundaryTest $command): EventCriteria
    {
        return EventCriteria::tag('course', $command->courseId);
    }

    #[DecisionBoundary]
    public static function secondBoundary(RateCourseForBoundaryTest $command): EventCriteria
    {
        return EventCriteria::tag('course', $command->courseId);
    }

    #[CommandHandler]
    public function rate(RateCourseForBoundaryTest $command): array
    {
        return [];
    }
}
