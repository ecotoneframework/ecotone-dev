<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Tagging;

use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\TaggedEventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 */
final class TaggedEventStoreTest extends TestCase
{
    public function test_loading_events_by_tag_returns_the_event_that_carries_it(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [StudentSubscribedToCourseForStoreTest::class],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        /** @var TaggedEventStore $taggedEventStore */
        $taggedEventStore = $ecotone->getServiceFromContainer(TaggedEventStore::class);

        $taggedEventStore->appendTo('ecotone_event_stream', [
            new StudentSubscribedToCourseForStoreTest('course-1', 'student-1'),
        ]);

        $loadedEvents = $taggedEventStore->load(EventCriteria::tag('course', 'course-1'));

        $this->assertCount(1, $loadedEvents->events);
        $this->assertEquals(
            new StudentSubscribedToCourseForStoreTest('course-1', 'student-1'),
            $loadedEvents->events[0]->getPayload(),
        );
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
