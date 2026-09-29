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
final class EventStoreGatewayLoadByCriteriaTest extends TestCase
{
    public function test_loading_events_by_criteria_through_the_gateway_returns_the_event_that_carries_it(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [StudentSubscribedForGatewayTest::class],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $eventStore = $ecotone->getGateway(EventStore::class);

        $eventStore->appendTo('ecotone_event_stream', [
            new StudentSubscribedForGatewayTest('course-1', 'student-1'),
        ]);

        $loadedEvents = $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'));

        $this->assertCount(1, $loadedEvents->events);
        $this->assertEquals(
            new StudentSubscribedForGatewayTest('course-1', 'student-1'),
            $loadedEvents->events[0]->getPayload(),
        );
    }

    public function test_or_criteria_through_the_gateway_returns_events_matching_either_criterion(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [StudentSubscribedForGatewayTest::class],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $eventStore = $ecotone->getGateway(EventStore::class);

        $eventStore->appendTo('ecotone_event_stream', [
            new StudentSubscribedForGatewayTest('course-1', 'student-1'),
            new StudentSubscribedForGatewayTest('course-2', 'student-2'),
        ]);

        $loadedEvents = $eventStore->loadByCriteria(
            EventCriteria::tag('course', 'course-1')->or(EventCriteria::tag('course', 'course-2')),
        );

        $this->assertCount(2, $loadedEvents->events);
    }
}

final readonly class StudentSubscribedForGatewayTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        #[EventTag('student')] public string $studentId,
    ) {
    }
}
