<?php
/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\Fixture\Calendar\CalendarCreated;
use Test\Ecotone\EventSourcing\Fixture\Calendar\CreateCalendar;
use Test\Ecotone\EventSourcing\Fixture\Calendar\EventsConverter;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingCreated;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingScheduled;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingWithEventSourcing;
use Test\Ecotone\EventSourcing\Fixture\Calendar\ScheduleMeetingWithEventSourcing;
use Test\Ecotone\EventSourcing\Fixture\EventSourcingCalendarWithInternalRecorder\CalendarWithInternalRecorder;
use Test\Ecotone\EventSourcing\Projecting\ProjectingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class MultiStreamProjectionTest extends ProjectingTestCase
{
    public function test_building_multi_stream_synchronous_projection(): void
    {
        $projection = $this->createMultiStreamProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $this->expectException(\RuntimeException::class);
        $ecotone->sendQueryWithRouting('getCalendar', 'cal-build-1');

        // create calendar and schedule meeting to drive projection entries
        $calendarId = 'cal-build-1';
        $meetingId = 'm-build-1';
        $ecotone->sendCommand(new CreateCalendar($calendarId));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing($calendarId, $meetingId));

        self::assertEquals([
            $meetingId => 'created',
        ], $ecotone->sendQueryWithRouting('getCalendar', $calendarId));
    }

    public function test_reset_and_delete_on_multi_stream_projection(): void
    {
        $projection = $this->createMultiStreamProjection();
        $ecotone = $this->bootstrapEcotone([$projection::class, CalendarWithInternalRecorder::class, MeetingWithEventSourcing::class, EventsConverter::class], [$projection, new EventsConverter()]);

        // init
        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        // seed some events across multiple streams (Calendar/Meeting)
        $calendarId = 'cal-reset-1';
        $meetingId = 'm-reset-1';
        $ecotone->sendCommand(new CreateCalendar($calendarId));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing($calendarId, $meetingId));

        // verify current state
        self::assertEquals([
            $meetingId => 'created',
        ], $ecotone->sendQueryWithRouting('getCalendar', $calendarId));

        // reset and trigger catch up
        $ecotone->resetProjection($projection::NAME)
            ->triggerProjection($projection::NAME);

        // after reset and catch-up, state should be re-built
        self::assertEquals([
            $meetingId => 'created',
        ], $ecotone->sendQueryWithRouting('getCalendar', $calendarId));

        // delete projection (in-memory)
        $ecotone->deleteProjection($projection::NAME);
        $this->expectException(\RuntimeException::class);
        $ecotone->sendQueryWithRouting('getCalendar', $calendarId);
    }

    private function createMultiStreamProjection(): object
    {
        $connection = $this->getConnection();

        // Configure FromStream with multiple streams: Calendar/Meeting aggregates
        // Real-world usage: projection reacts to Calendar/Meeting events to generate a read model
        return new #[ProjectionV2(self::NAME), FromStream(CalendarWithInternalRecorder::class), FromStream(MeetingWithEventSourcing::class)] class () {
            public const NAME = 'calendar_multi_stream_projection';

            private array $calendars = [];

            #[QueryHandler('getCalendar')]
            public function getCalendar(string $calendarId): array
            {
                return $this->calendars[$calendarId] ?? throw new \RuntimeException("Calendar with id {$calendarId} not found");
            }

            #[EventHandler]
            public function whenCalendarCreated(CalendarCreated $event): void
            {
                $this->calendars[$event->calendarId] = [];
            }

            #[EventHandler]
            public function whenMeetingScheduled(MeetingScheduled $event): void
            {
                if (! array_key_exists($event->calendarId, $this->calendars)) {
                    throw new \RuntimeException('Meeting scheduled before calendar was created');
                }
                $this->calendars[$event->calendarId][$event->meetingId] = 'scheduled';
            }

            #[EventHandler]
            public function whenMeetingCreated(MeetingCreated $event): void
            {
                if (! array_key_exists($event->calendarId, $this->calendars)) {
                    throw new \RuntimeException('Meeting created before calendar was created');
                }
                $this->calendars[$event->calendarId][$event->meetingId] = 'created';
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->calendars = [];
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->calendars = [];
            }
        };
    }

    private function bootstrapEcotone(array $classesToResolve, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classesToResolve, [
                CalendarWithInternalRecorder::class,
                MeetingWithEventSourcing::class, EventsConverter::class
            ]),
            containerOrAvailableServices: array_merge($services, [new EventsConverter(), self::getConnectionFactory()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ])),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
