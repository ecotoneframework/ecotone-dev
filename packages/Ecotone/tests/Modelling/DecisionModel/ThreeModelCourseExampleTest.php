<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventSourcingSaga;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 */
final class ThreeModelCourseExampleTest extends TestCase
{
    private const CLASSES = [
        CourseSubscriptionsForThreeModelTest::class,
        CourseCapacityForThreeModelTest::class,
        StudentCoursesForThreeModelTest::class,
        StudentSubscriptionForThreeModelTest::class,
        CourseDefinedForThreeModelTest::class,
        CourseCapacityChangedForThreeModelTest::class,
        StudentSubscribedToCourseForThreeModelTest::class,
        EnrollmentNotificationSagaForThreeModelTest::class,
    ];

    public function test_subscribing_when_course_is_full_throws_business_exception(): void
    {
        $ecotone = $this->bootstrap();

        $ecotone->withEvents([new CourseDefinedForThreeModelTest('course-1', 1)]);
        $ecotone->sendCommand(new SubscribeStudentToCourseForThreeModelTest('course-1', 'student-1'));

        $this->expectException(CourseIsFullForThreeModelTest::class);
        $ecotone->sendCommand(new SubscribeStudentToCourseForThreeModelTest('course-1', 'student-2'));
    }

    public function test_subscribing_twice_for_the_same_course_is_a_no_op(): void
    {
        $ecotone = $this->bootstrap();

        $ecotone->withEvents([new CourseDefinedForThreeModelTest('course-1', 5)]);
        $ecotone->sendCommand(new SubscribeStudentToCourseForThreeModelTest('course-1', 'student-1'));
        $ecotone->sendCommand(new SubscribeStudentToCourseForThreeModelTest('course-1', 'student-1'));

        $this->assertCount(2, $ecotone->getEventStreamEvents('ecotone_event_stream'));
    }

    public function test_course_capacity_model_is_reused_across_two_handlers_with_different_boundaries(): void
    {
        $ecotone = $this->bootstrap();

        $ecotone->withEvents([new CourseDefinedForThreeModelTest('course-1', 3)]);
        $ecotone->sendCommand(new SubscribeStudentToCourseForThreeModelTest('course-1', 'student-1'));
        $ecotone->sendCommand(new ChangeCourseCapacityForThreeModelTest('course-1', 1));

        $this->expectException(CapacityBelowSubscriptionsForThreeModelTest::class);
        $ecotone->sendCommand(new ChangeCourseCapacityForThreeModelTest('course-1', 0));
    }

    public function test_subscribed_event_reaches_event_handler_and_saga(): void
    {
        $ecotone = $this->bootstrap();

        $ecotone->withEvents([new CourseDefinedForThreeModelTest('course-1', 5)]);
        $ecotone->sendCommand(new SubscribeStudentToCourseForThreeModelTest('course-1', 'student-1'));

        $this->assertSame(['course-1:student-1'], EventHandlerRecorderForThreeModelTest::$received);
    }

    private function bootstrap()
    {
        EventHandlerRecorderForThreeModelTest::$received = [];

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: self::CLASSES,
            containerOrAvailableServices: [new CourseSubscriptionsForThreeModelTest(), new EnrollmentNotificationSagaForThreeModelTest()],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}

final readonly class SubscribeStudentToCourseForThreeModelTest
{
    public function __construct(
        public string $courseId,
        public string $studentId,
    ) {
    }
}

final readonly class ChangeCourseCapacityForThreeModelTest
{
    public function __construct(
        public string $courseId,
        public int $capacity,
    ) {
    }
}

final readonly class CourseDefinedForThreeModelTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        public int $capacity,
    ) {
    }
}

final readonly class CourseCapacityChangedForThreeModelTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        public int $capacity,
    ) {
    }
}

final readonly class StudentSubscribedToCourseForThreeModelTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        #[EventTag('student')] public string $studentId,
    ) {
    }
}

final class CourseIsFullForThreeModelTest extends \RuntimeException
{
}

final class StudentHasTooManyCoursesForThreeModelTest extends \RuntimeException
{
}

final class CapacityBelowSubscriptionsForThreeModelTest extends \RuntimeException
{
}

#[DecisionModel]
final class CourseCapacityForThreeModelTest
{
    private int $capacity = 0;
    private int $seatsTaken = 0;

    #[EventSourcingHandler]
    public function defined(CourseDefinedForThreeModelTest $event): void
    {
        $this->capacity = $event->capacity;
    }

    #[EventSourcingHandler]
    public function capacityChanged(CourseCapacityChangedForThreeModelTest $event): void
    {
        $this->capacity = $event->capacity;
    }

    #[EventSourcingHandler]
    public function seatTaken(StudentSubscribedToCourseForThreeModelTest $event): void
    {
        $this->seatsTaken++;
    }

    public function hasFreeSeat(): bool
    {
        return $this->seatsTaken < $this->capacity;
    }

    public function seatsTaken(): int
    {
        return $this->seatsTaken;
    }
}

#[DecisionModel(tags: ['student'])]
final class StudentCoursesForThreeModelTest
{
    private int $courses = 0;

    #[EventSourcingHandler]
    public function joined(StudentSubscribedToCourseForThreeModelTest $event): void
    {
        $this->courses++;
    }

    public function canJoinAnother(): bool
    {
        return $this->courses < 5;
    }
}

#[DecisionModel]
final class StudentSubscriptionForThreeModelTest
{
    private bool $exists = false;

    #[EventSourcingHandler]
    public function subscribed(StudentSubscribedToCourseForThreeModelTest $event): void
    {
        $this->exists = true;
    }

    public function exists(): bool
    {
        return $this->exists;
    }
}

final class EventHandlerRecorderForThreeModelTest
{
    /** @var string[] */
    public static array $received = [];
}

final class CourseSubscriptionsForThreeModelTest
{
    #[CommandHandler]
    public function subscribe(
        SubscribeStudentToCourseForThreeModelTest $command,
        CourseCapacityForThreeModelTest $course,
        StudentCoursesForThreeModelTest $student,
        StudentSubscriptionForThreeModelTest $subscription,
    ): array {
        if ($subscription->exists()) {
            return [];
        }
        if (! $course->hasFreeSeat()) {
            throw new CourseIsFullForThreeModelTest();
        }
        if (! $student->canJoinAnother()) {
            throw new StudentHasTooManyCoursesForThreeModelTest();
        }

        return [new StudentSubscribedToCourseForThreeModelTest($command->courseId, $command->studentId)];
    }

    #[CommandHandler]
    public function changeCapacity(ChangeCourseCapacityForThreeModelTest $command, CourseCapacityForThreeModelTest $course): array
    {
        if ($course->seatsTaken() > $command->capacity) {
            throw new CapacityBelowSubscriptionsForThreeModelTest();
        }

        return [new CourseCapacityChangedForThreeModelTest($command->courseId, $command->capacity)];
    }

    #[EventHandler]
    public function onSubscribed(StudentSubscribedToCourseForThreeModelTest $event): void
    {
        EventHandlerRecorderForThreeModelTest::$received[] = $event->courseId . ':' . $event->studentId;
    }
}

#[EventSourcingSaga]
final class EnrollmentNotificationSagaForThreeModelTest
{
    #[Identifier]
    private string $courseId;

    #[EventSourcingHandler]
    public function onSubscribed(StudentSubscribedToCourseForThreeModelTest $event): void
    {
        $this->courseId = $event->courseId;
    }
}
