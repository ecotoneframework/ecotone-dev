<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Tagging;

use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class EventStoreTagLoadingTest extends TestCase
{
    public function test_loading_events_by_tag_returns_the_event_that_carries_it(): void
    {
        $eventStore = $this->bootstrapEventStoreForTagLoadingTest();

        $eventStore->appendTo('ecotone_event_stream', [
            new StudentSubscribedToCourseForStoreTest('course-1', 'student-1'),
        ]);

        $loadedEvents = $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'));

        $this->assertCount(1, $loadedEvents->events);
        $this->assertEquals(
            new StudentSubscribedToCourseForStoreTest('course-1', 'student-1'),
            $loadedEvents->events[0]->getPayload(),
        );
    }

    public function test_and_criteria_requires_all_tags_to_be_present_on_the_event(): void
    {
        $eventStore = $this->bootstrapEventStoreForTagLoadingTest();

        $eventStore->appendTo('ecotone_event_stream', [
            new StudentSubscribedToCourseForStoreTest('course-1', 'student-1'),
            new StudentSubscribedToCourseForStoreTest('course-1', 'student-2'),
        ]);

        $loadedEvents = $eventStore->loadByCriteria(
            EventCriteria::tag('course', 'course-1')->andTag('student', 'student-2')
        );

        $this->assertCount(1, $loadedEvents->events);
        $this->assertSame('student-2', $loadedEvents->events[0]->getPayload()->studentId);
    }

    public function test_or_criteria_returns_events_matching_either_criterion(): void
    {
        $eventStore = $this->bootstrapEventStoreForTagLoadingTest();

        $eventStore->appendTo('ecotone_event_stream', [
            new StudentSubscribedToCourseForStoreTest('course-1', 'student-1'),
            new StudentSubscribedToCourseForStoreTest('course-2', 'student-2'),
        ]);

        $loadedEvents = $eventStore->loadByCriteria(
            EventCriteria::tag('course', 'course-1')->or(EventCriteria::tag('course', 'course-2')),
        );

        $this->assertCount(2, $loadedEvents->events);
    }

    public function test_type_filter_excludes_events_of_other_types(): void
    {
        $eventStore = $this->bootstrapEventStoreForTagLoadingTest();

        $eventStore->appendTo('ecotone_event_stream', [
            new StudentSubscribedToCourseForStoreTest('course-1', 'student-1'),
            new CourseCapacityChangedForStoreTest('course-1', 10),
        ]);

        $loadedEvents = $eventStore->loadByCriteria(
            EventCriteria::tag('course', 'course-1')->ofTypes(CourseCapacityChangedForStoreTest::class)
        );

        $this->assertCount(1, $loadedEvents->events);
        $this->assertInstanceOf(CourseCapacityChangedForStoreTest::class, $loadedEvents->events[0]->getPayload());
    }

    public function test_event_matching_two_criteria_is_returned_only_once(): void
    {
        $eventStore = $this->bootstrapEventStoreForTagLoadingTest();

        $eventStore->appendTo('ecotone_event_stream', [
            new StudentSubscribedToCourseForStoreTest('course-1', 'student-1'),
        ]);

        $loadedEvents = $eventStore->loadByCriteria(
            EventCriteria::tag('course', 'course-1')->or(EventCriteria::tag('student', 'student-1')),
        );

        $this->assertCount(1, $loadedEvents->events);
    }

    public function test_conditional_append_succeeds_when_no_conflicting_event_was_appended(): void
    {
        $eventStore = $this->bootstrapEventStoreForTagLoadingTest();

        $eventStore->appendTo('ecotone_event_stream', [
            new StudentSubscribedToCourseForStoreTest('course-1', 'student-1'),
        ]);

        $loadedEvents = $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'));

        $eventStore->appendTo(
            'ecotone_event_stream',
            [new StudentSubscribedToCourseForStoreTest('course-1', 'student-2')],
            $loadedEvents->appendCondition,
        );

        $this->assertCount(
            2,
            $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'))->events,
        );
    }

    public function test_conditional_append_fails_when_a_conflicting_event_was_appended_since_the_read(): void
    {
        $eventStore = $this->bootstrapEventStoreForTagLoadingTest();

        $eventStore->appendTo('ecotone_event_stream', [
            new StudentSubscribedToCourseForStoreTest('course-1', 'student-1'),
        ]);

        $loadedEvents = $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'));

        $eventStore->appendTo('ecotone_event_stream', [
            new StudentSubscribedToCourseForStoreTest('course-1', 'student-2'),
        ]);

        $this->expectException(DecisionModelConcurrencyException::class);

        $eventStore->appendTo(
            'ecotone_event_stream',
            [new StudentSubscribedToCourseForStoreTest('course-1', 'student-3')],
            $loadedEvents->appendCondition,
        );
    }

    public function test_conditional_append_fails_when_events_conflict_via_an_unconditional_append_on_a_never_written_tag(): void
    {
        $eventStore = $this->bootstrapEventStoreForTagLoadingTest();

        $loadedEvents = $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'));

        $eventStore->appendTo('ecotone_event_stream', [
            new StudentSubscribedToCourseForStoreTest('course-1', 'student-1'),
        ]);

        $this->expectException(DecisionModelConcurrencyException::class);

        $eventStore->appendTo(
            'ecotone_event_stream',
            [new StudentSubscribedToCourseForStoreTest('course-1', 'student-2')],
            $loadedEvents->appendCondition,
        );
    }

    public function test_conditional_append_is_unaffected_by_an_event_carrying_a_disjoint_tag(): void
    {
        $eventStore = $this->bootstrapEventStoreForTagLoadingTest();

        $eventStore->appendTo('ecotone_event_stream', [
            new StudentSubscribedToCourseForStoreTest('course-1', 'student-1'),
        ]);

        $loadedEvents = $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'));

        $eventStore->appendTo('ecotone_event_stream', [
            new StudentSubscribedToCourseForStoreTest('course-2', 'student-9'),
        ]);

        $eventStore->appendTo(
            'ecotone_event_stream',
            [new StudentSubscribedToCourseForStoreTest('course-1', 'student-2')],
            $loadedEvents->appendCondition,
        );

        $this->assertCount(
            2,
            $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'))->events,
        );
    }

    public function test_delete_clears_the_tag_index_for_that_stream(): void
    {
        $eventStore = $this->bootstrapEventStoreForTagLoadingTest();

        $eventStore->appendTo('ecotone_event_stream', [
            new StudentSubscribedToCourseForStoreTest('course-1', 'student-1'),
        ]);

        $ecotone = $this->ecotone;
        $ecotone->deleteEventStream('ecotone_event_stream');

        $this->assertCount(0, $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'))->events);
    }

    private ?FlowTestSupport $ecotone = null;

    private function bootstrapEventStoreForTagLoadingTest(): EventStore
    {
        $this->ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [StudentSubscribedToCourseForStoreTest::class, CourseCapacityChangedForStoreTest::class],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        return $this->ecotone->getServiceFromContainer(EventStore::class);
    }
}

final readonly class CourseCapacityChangedForStoreTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        public int $capacity,
    ) {
    }
}

final readonly class StudentSubscribedToCourseForStoreTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        #[EventTag('student')] public string $studentId,
    ) {
    }
}
