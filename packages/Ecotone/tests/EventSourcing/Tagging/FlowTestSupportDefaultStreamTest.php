<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Tagging;

use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\TaggedEventStore;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 */
final class FlowTestSupportDefaultStreamTest extends TestCase
{
    public function test_with_events_writes_to_the_ecotone_event_stream_by_default(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [CourseDefinedForDefaultStreamTest::class],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->withEvents([new CourseDefinedForDefaultStreamTest('course-1')]);

        $this->assertTrue($ecotone->getServiceFromContainer(EventStore::class)->hasStream('ecotone_event_stream'));

        /** @var TaggedEventStore $taggedEventStore */
        $taggedEventStore = $ecotone->getServiceFromContainer(TaggedEventStore::class);
        $this->assertCount(1, $taggedEventStore->load(EventCriteria::tag('course', 'course-1'))->events);
    }
}

final readonly class CourseDefinedForDefaultStreamTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
    ) {
    }
}
