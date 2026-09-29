<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Tagging;

use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class FlowTestSupportDefaultStreamTest extends TestCase
{
    public function test_with_events_writes_to_the_ecotone_event_stream_by_default(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [CourseDefinedForDefaultStreamTest::class],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->withEvents([new CourseDefinedForDefaultStreamTest('course-1')]);

        $this->assertTrue($ecotone->getServiceFromContainer(EventStore::class)->hasStream('ecotone_event_stream'));

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getServiceFromContainer(EventStore::class);
        $this->assertCount(1, $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'))->events);
    }
}

final readonly class CourseDefinedForDefaultStreamTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
    ) {
    }
}
