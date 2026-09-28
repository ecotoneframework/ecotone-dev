<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * licence Enterprise
 * @internal
 */
final class DecisionModelBasicTest extends TestCase
{
    public function test_command_handler_injects_a_decision_model_folded_from_tagged_events(): void
    {
        $courseSubscriptions = new class () {
            #[CommandHandler]
            public function subscribe(SubscribeStudentToCourse $command, CourseCapacityForBasicTest $course): array
            {
                if (! $course->hasFreeSeat()) {
                    throw new CourseIsFullForBasicTest();
                }

                return [new StudentSubscribedToCourseForBasicTest($command->courseId, $command->studentId)];
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$courseSubscriptions::class, CourseCapacityForBasicTest::class, CourseDefinedForBasicTest::class, StudentSubscribedToCourseForBasicTest::class],
            containerOrAvailableServices: [$courseSubscriptions],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->withEvents([new CourseDefinedForBasicTest('course-1', 1)]);

        $ecotone->sendCommand(new SubscribeStudentToCourse('course-1', 'student-1'));

        $this->expectException(CourseIsFullForBasicTest::class);
        $ecotone->sendCommand(new SubscribeStudentToCourse('course-1', 'student-2'));
    }
}

final readonly class SubscribeStudentToCourse
{
    public function __construct(
        public string $courseId,
        public string $studentId,
    ) {
    }
}

final readonly class CourseDefinedForBasicTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        public int $capacity,
    ) {
    }
}

final readonly class StudentSubscribedToCourseForBasicTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        #[EventTag('student')] public string $studentId,
    ) {
    }
}

/**
 * @internal
 */
final class CourseIsFullForBasicTest extends RuntimeException
{
}

#[DecisionModel]
final class CourseCapacityForBasicTest
{
    private int $capacity = 0;
    private int $seatsTaken = 0;

    #[EventSourcingHandler]
    public function defined(CourseDefinedForBasicTest $event): void
    {
        $this->capacity = $event->capacity;
    }

    #[EventSourcingHandler]
    public function seatTaken(StudentSubscribedToCourseForBasicTest $event): void
    {
        $this->seatsTaken++;
    }

    public function hasFreeSeat(): bool
    {
        return $this->seatsTaken < $this->capacity;
    }
}
