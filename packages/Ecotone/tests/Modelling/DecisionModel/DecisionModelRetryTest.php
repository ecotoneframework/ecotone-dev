<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\InstantRetry;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\TaggedEventStore;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Modelling\Config\DatabaseTransaction\TransactionStatusTracker;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 */
final class DecisionModelRetryTest extends TestCase
{
    public function test_without_retry_configured_the_conflict_surfaces_with_tag_and_versions_in_the_message(): void
    {
        $ecotone = $this->bootstrap();

        $ecotone->withEvents([new CourseDefinedForRetryTest('course-1', 5)]);

        $injector = $ecotone->getServiceFromContainer(CompetingWriteInjectorForRetryTest::class);
        $injector->arm();

        try {
            $ecotone->sendCommand(new SubscribeToCourseForRetryTest('course-1', 'student-1'));
            $this->fail('Expected a DecisionModelConcurrencyException');
        } catch (DecisionModelConcurrencyException $exception) {
            $this->assertStringContainsString('course', $exception->getMessage());
            $this->assertStringContainsString('course-1', $exception->getMessage());
        }
    }

    public function test_instant_retry_configuration_makes_an_injected_conflict_succeed_on_the_second_pass(): void
    {
        $ecotone = $this->bootstrap(
            InstantRetryConfiguration::createWithDefaults()
                ->withCommandBusRetry(true, 3, [DecisionModelConcurrencyException::class])
        );

        $ecotone->withEvents([new CourseDefinedForRetryTest('course-1', 5)]);

        $injector = $ecotone->getServiceFromContainer(CompetingWriteInjectorForRetryTest::class);
        $injector->arm();

        $ecotone->sendCommand(new SubscribeToCourseForRetryTest('course-1', 'student-1'));

        /** @var TaggedEventStore $taggedEventStore */
        $taggedEventStore = $ecotone->getServiceFromContainer(TaggedEventStore::class);
        $this->assertCount(1, $taggedEventStore->load(EventCriteria::tag('student', 'student-1'))->events);
    }

    public function test_no_retry_happens_inside_an_outer_database_transaction(): void
    {
        $ecotone = $this->bootstrap(
            InstantRetryConfiguration::createWithDefaults()
                ->withCommandBusRetry(true, 3, [DecisionModelConcurrencyException::class])
        );

        $ecotone->withEvents([new CourseDefinedForRetryTest('course-1', 5)]);

        $injector = $ecotone->getServiceFromContainer(CompetingWriteInjectorForRetryTest::class);
        $injector->arm();

        /** @var TransactionStatusTracker $transactionStatusTracker */
        $transactionStatusTracker = $ecotone->getServiceFromContainer(TransactionStatusTracker::class);
        $transactionStatusTracker->markAsInsideTransaction();

        try {
            $this->expectException(DecisionModelConcurrencyException::class);
            $ecotone->sendCommand(new SubscribeToCourseForRetryTest('course-1', 'student-1'));
        } finally {
            $transactionStatusTracker->markAsOutsideTransaction();
        }
    }

    public function test_enterprise_instant_retry_on_a_command_bus_interface_works_the_same_as_the_configuration(): void
    {
        $handler = new SubscribeHandlerForRetryTest();
        $injector = new CompetingWriteInjectorForRetryTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [
                $handler::class,
                CourseForRetryTest::class,
                CourseDefinedForRetryTest::class,
                StudentSubscribedForRetryTest::class,
                CompetingWriteInjectorForRetryTest::class,
                RetryCommandBusForRetryTest::class,
            ],
            containerOrAvailableServices: [$handler, $injector],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->withEvents([new CourseDefinedForRetryTest('course-1', 5)]);

        $injector->arm();

        $ecotone->getGateway(RetryCommandBusForRetryTest::class)->send(new SubscribeToCourseForRetryTest('course-1', 'student-1'));

        /** @var TaggedEventStore $taggedEventStore */
        $taggedEventStore = $ecotone->getServiceFromContainer(TaggedEventStore::class);
        $this->assertCount(1, $taggedEventStore->load(EventCriteria::tag('student', 'student-1'))->events);
    }

    private function bootstrap(?InstantRetryConfiguration $retryConfiguration = null)
    {
        $handler = new SubscribeHandlerForRetryTest();
        $injector = new CompetingWriteInjectorForRetryTest();

        $configuration = ServiceConfiguration::createWithDefaults();
        if ($retryConfiguration !== null) {
            $configuration = $configuration->withExtensionObjects([$retryConfiguration]);
        }

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, CourseForRetryTest::class, CourseDefinedForRetryTest::class, StudentSubscribedForRetryTest::class, CompetingWriteInjectorForRetryTest::class],
            containerOrAvailableServices: [$handler, $injector],
            configuration: $configuration,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}

#[InstantRetry(retryTimes: 3, exceptions: [DecisionModelConcurrencyException::class])]
interface RetryCommandBusForRetryTest extends CommandBus
{
}

final readonly class SubscribeToCourseForRetryTest
{
    public function __construct(
        public string $courseId,
        public string $studentId,
    ) {
    }
}

final readonly class CourseDefinedForRetryTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        public int $capacity,
    ) {
    }
}

final readonly class StudentSubscribedForRetryTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        #[EventTag('student')] public string $studentId,
    ) {
    }
}

#[DecisionModel]
final class CourseForRetryTest
{
    private int $capacity = 0;
    private int $seatsTaken = 0;

    #[EventSourcingHandler]
    public function defined(CourseDefinedForRetryTest $event): void
    {
        $this->capacity = $event->capacity;
    }

    #[EventSourcingHandler]
    public function seatTaken(StudentSubscribedForRetryTest $event): void
    {
        $this->seatsTaken++;
    }

    public function hasFreeSeat(): bool
    {
        return $this->seatsTaken < $this->capacity;
    }
}

final class CompetingWriteInjectorForRetryTest
{
    private bool $armed = false;

    public function arm(): void
    {
        $this->armed = true;
    }

    public function maybeInject(TaggedEventStore $taggedEventStore): void
    {
        if (! $this->armed) {
            return;
        }

        $this->armed = false;
        $taggedEventStore->appendTo('ecotone_event_stream', [
            new StudentSubscribedForRetryTest('course-1', 'interloper'),
        ]);
    }
}

final class SubscribeHandlerForRetryTest
{
    #[CommandHandler]
    public function subscribe(
        SubscribeToCourseForRetryTest $command,
        CourseForRetryTest $course,
        #[Reference] CompetingWriteInjectorForRetryTest $injector,
        #[Reference] TaggedEventStore $taggedEventStore,
    ): array {
        $injector->maybeInject($taggedEventStore);

        return [new StudentSubscribedForRetryTest($command->courseId, $command->studentId)];
    }
}
