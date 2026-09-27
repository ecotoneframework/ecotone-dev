<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\EventSourcing\Attribute\ProjectionState;
use Ecotone\EventSourcing\Attribute\ProjectionStateGateway;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Lite\Test\TestConfiguration;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Projecting\Attribute\ProjectionExecution;
use Ecotone\Projecting\Attribute\ProjectionRebuild;
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
final class MultiStreamTrackingTest extends ProjectingTestCase
{
    public function test_projects_each_stream_from_its_own_position_without_duplicates(): void
    {
        $recorder = new ProjectedEventsRecorder();
        $projection = new #[ProjectionV2('multi_stream_tracking'), FromStream(CalendarWithInternalRecorder::class), FromStream(MeetingWithEventSourcing::class)] class ($recorder) extends RecordingCalendarProjection {
        };

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->sendCommand(new CreateCalendar('c1'));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing('c1', 'm1'));
        $ecotone->sendCommand(new CreateCalendar('c2'));

        self::assertSame([
            'calendar_created:c1' => 1,
            'meeting_scheduled:m1' => 1,
            'meeting_created:m1' => 1,
            'calendar_created:c2' => 1,
        ], $recorder->projected);
    }

    public function test_rebuild_resets_multi_stream_projection_only_once(): void
    {
        $recorder = new ProjectedEventsRecorder();
        $projection = new #[ProjectionV2('multi_stream_rebuild_once'), ProjectionRebuild, FromStream(CalendarWithInternalRecorder::class), FromStream(MeetingWithEventSourcing::class)] class ($recorder) extends RecordingCalendarProjection {
        };

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->sendCommand(new CreateCalendar('c1'));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing('c1', 'm1'));

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'multi_stream_rebuild_once']);

        self::assertSame(1, $recorder->resets);
        self::assertSame([
            'calendar_created:c1' => 1,
            'meeting_scheduled:m1' => 1,
            'meeting_created:m1' => 1,
        ], $recorder->projected);
    }

    public function test_rebuild_replays_all_events_when_streams_have_uneven_number_of_events(): void
    {
        $recorder = new ProjectedEventsRecorder();
        $projection = new #[ProjectionV2('multi_stream_rebuild_uneven'), ProjectionRebuild, ProjectionExecution(eventLoadingBatchSize: 20), FromStream(CalendarWithInternalRecorder::class), FromStream(MeetingWithEventSourcing::class)] class ($recorder) extends RecordingCalendarProjection {
        };

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        for ($i = 1; $i <= 40; $i++) {
            $ecotone->sendCommand(new CreateCalendar("c{$i}"));
        }
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing('c1', 'm1'));

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'multi_stream_rebuild_uneven']);

        self::assertCount(42, $recorder->projected);
    }

    public function test_async_rebuild_of_multi_stream_projection_sends_single_message(): void
    {
        $recorder = new ProjectedEventsRecorder();
        $projection = new #[ProjectionV2('multi_stream_async_rebuild'), ProjectionRebuild(asyncChannelName: 'multi_stream_rebuild_channel'), FromStream(CalendarWithInternalRecorder::class), FromStream(MeetingWithEventSourcing::class)] class ($recorder) extends RecordingCalendarProjection {
        };

        $ecotone = $this->bootstrapEcotone(
            [$projection::class],
            [$projection],
            [SimpleMessageChannelBuilder::createQueueChannel('multi_stream_rebuild_channel')],
            TestConfiguration::createWithDefaults()->withSpyOnChannel('multi_stream_rebuild_channel'),
        );

        $ecotone->sendCommand(new CreateCalendar('c1'));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing('c1', 'm1'));

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'multi_stream_async_rebuild']);
        self::assertCount(1, $ecotone->getRecordedMessagePayloadsFrom('multi_stream_rebuild_channel'));

        $ecotone->run('multi_stream_rebuild_channel', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));

        self::assertSame(1, $recorder->resets);
        self::assertCount(3, $recorder->projected);
    }

    public function test_async_rebuild_of_parallel_projection_resets_once_and_replays_each_stream_separately(): void
    {
        $recorder = new ProjectedEventsRecorder();
        $projection = new #[ProjectionV2('parallel_async_rebuild'), ProjectionExecution(processStreamsInParallel: true), ProjectionRebuild(asyncChannelName: 'parallel_rebuild_channel'), FromStream(CalendarWithInternalRecorder::class), FromStream(MeetingWithEventSourcing::class)] class ($recorder) extends RecordingCalendarProjection {
        };

        $ecotone = $this->bootstrapEcotone(
            [$projection::class],
            [$projection],
            [SimpleMessageChannelBuilder::createQueueChannel('parallel_rebuild_channel')],
            TestConfiguration::createWithDefaults()->withSpyOnChannel('parallel_rebuild_channel'),
        );

        $ecotone->sendCommand(new CreateCalendar('c1'));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing('c1', 'm1'));

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'parallel_async_rebuild']);

        self::assertSame(1, $recorder->resets);
        self::assertSame([], $recorder->projected);
        self::assertCount(2, $ecotone->getRecordedMessagePayloadsFrom('parallel_rebuild_channel'));

        $ecotone->run('parallel_rebuild_channel', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $projectedAfterFirstStream = $recorder->projected;
        $ecotone->run('parallel_rebuild_channel', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));

        self::assertNotEmpty($projectedAfterFirstStream);
        self::assertLessThan(3, count($projectedAfterFirstStream));
        self::assertSame(1, $recorder->resets);
        self::assertEqualsCanonicalizing([
            'calendar_created:c1' => 1,
            'meeting_scheduled:m1' => 1,
            'meeting_created:m1' => 1,
        ], $recorder->projected);
    }

    public function test_synchronous_rebuild_of_parallel_projection_replays_all_streams(): void
    {
        $recorder = new ProjectedEventsRecorder();
        $projection = new #[ProjectionV2('parallel_sync_rebuild'), ProjectionExecution(processStreamsInParallel: true), ProjectionRebuild, FromStream(CalendarWithInternalRecorder::class), FromStream(MeetingWithEventSourcing::class)] class ($recorder) extends RecordingCalendarProjection {
        };

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->sendCommand(new CreateCalendar('c1'));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing('c1', 'm1'));

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'parallel_sync_rebuild']);

        self::assertSame(1, $recorder->resets);
        self::assertEqualsCanonicalizing([
            'calendar_created:c1' => 1,
            'meeting_scheduled:m1' => 1,
            'meeting_created:m1' => 1,
        ], $recorder->projected);
    }

    public function test_sequential_projection_shares_projection_state_across_streams(): void
    {
        $recorder = new ProjectedEventsRecorder();
        $projection = new #[ProjectionV2('sequential_shared_state'), FromStream(CalendarWithInternalRecorder::class), FromStream(MeetingWithEventSourcing::class)] class ($recorder) extends StateCountingCalendarProjection {
        };

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->sendCommand(new CreateCalendar('c1'));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing('c1', 'm1'));

        self::assertSame([
            'calendar_created:c1' => 0,
            'meeting_scheduled:m1' => 1,
            'meeting_created:m1' => 2,
        ], $recorder->stateSeenBy);
    }

    public function test_parallel_projection_keeps_projection_state_per_stream(): void
    {
        $recorder = new ProjectedEventsRecorder();
        $projection = new #[ProjectionV2('parallel_state_per_stream'), ProjectionExecution(processStreamsInParallel: true), FromStream(CalendarWithInternalRecorder::class), FromStream(MeetingWithEventSourcing::class)] class ($recorder) extends StateCountingCalendarProjection {
        };

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->sendCommand(new CreateCalendar('c1'));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing('c1', 'm1'));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing('c1', 'm2'));

        self::assertSame([
            'calendar_created:c1' => 0,
            'meeting_scheduled:m1' => 1,
            'meeting_created:m1' => 0,
            'meeting_scheduled:m2' => 2,
            'meeting_created:m2' => 1,
        ], $recorder->stateSeenBy);
    }

    public function test_switching_between_sequential_and_parallel_processing_continues_from_tracked_positions(): void
    {
        $recorder = new ProjectedEventsRecorder();
        $sequentialProjection = new #[ProjectionV2('switching_processing_mode'), FromStream(CalendarWithInternalRecorder::class), FromStream(MeetingWithEventSourcing::class)] class ($recorder) extends RecordingCalendarProjection {
        };
        $parallelProjection = new #[ProjectionV2('switching_processing_mode'), ProjectionExecution(processStreamsInParallel: true), FromStream(CalendarWithInternalRecorder::class), FromStream(MeetingWithEventSourcing::class)] class ($recorder) extends RecordingCalendarProjection {
        };

        $sequential = $this->bootstrapEcotone([$sequentialProjection::class], [$sequentialProjection]);
        $sequential->sendCommand(new CreateCalendar('c1'));
        $sequential->sendCommand(new ScheduleMeetingWithEventSourcing('c1', 'm1'));

        $parallel = $this->bootstrapEcotone([$parallelProjection::class], [$parallelProjection]);
        $parallel->sendCommand(new CreateCalendar('c2'));
        $parallel->sendCommand(new ScheduleMeetingWithEventSourcing('c2', 'm2'));

        $sequentialAgain = $this->bootstrapEcotone([$sequentialProjection::class], [$sequentialProjection]);
        $sequentialAgain->sendCommand(new CreateCalendar('c3'));
        $sequentialAgain->sendCommand(new ScheduleMeetingWithEventSourcing('c3', 'm3'));

        self::assertSame([
            'calendar_created:c1' => 1,
            'meeting_scheduled:m1' => 1,
            'meeting_created:m1' => 1,
            'calendar_created:c2' => 1,
            'meeting_scheduled:m2' => 1,
            'meeting_created:m2' => 1,
            'calendar_created:c3' => 1,
            'meeting_scheduled:m3' => 1,
            'meeting_created:m3' => 1,
        ], $recorder->projected);
    }

    public function test_continues_from_positions_stored_in_legacy_combined_format(): void
    {
        $recorder = new ProjectedEventsRecorder();
        $projection = new #[ProjectionV2('legacy_combined_position'), FromStream(CalendarWithInternalRecorder::class), FromStream(MeetingWithEventSourcing::class)] class ($recorder) extends RecordingCalendarProjection {
        };

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);
        $ecotone->sendCommand(new CreateCalendar('c1'));
        $ecotone->sendCommand(new CreateCalendar('c2'));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing('c1', 'm1'));

        $this->replaceTrackingWithLegacyCombinedPosition(
            'legacy_combined_position',
            CalendarWithInternalRecorder::class . '=2:;' . MeetingWithEventSourcing::class . '=1:;',
        );

        $ecotone->triggerProjection('legacy_combined_position');
        $ecotone->triggerProjection('legacy_combined_position');

        self::assertSame([
            'calendar_created:c1' => 1,
            'calendar_created:c2' => 1,
            'meeting_scheduled:m1' => 2,
            'meeting_created:m1' => 1,
        ], $recorder->projected);
    }

    public function test_parallel_processing_requires_enterprise_licence(): void
    {
        $projection = new #[ProjectionV2('parallel_without_licence'), ProjectionExecution(processStreamsInParallel: true), FromStream(CalendarWithInternalRecorder::class), FromStream(MeetingWithEventSourcing::class)] class (new ProjectedEventsRecorder()) extends RecordingCalendarProjection {
        };

        $this->expectException(LicensingException::class);

        $this->bootstrapEcotone([$projection::class], [$projection], licenceKey: null);
    }

    public function test_projection_state_gateway_cannot_be_used_with_parallel_processing(): void
    {
        $projection = new #[ProjectionV2('parallel_with_state_gateway'), ProjectionExecution(processStreamsInParallel: true), FromStream(CalendarWithInternalRecorder::class), FromStream(MeetingWithEventSourcing::class)] class (new ProjectedEventsRecorder()) extends StateCountingCalendarProjection {
        };

        $this->expectException(ConfigurationException::class);

        $this->bootstrapEcotone([$projection::class, ParallelProjectionStateGateway::class], [$projection]);
    }

    private function replaceTrackingWithLegacyCombinedPosition(string $projectionName, string $combinedPosition): void
    {
        $connection = self::getConnection();
        $connection->executeStatement('DELETE FROM ecotone_projection_state WHERE projection_name = ?', [$projectionName]);
        $connection->executeStatement(
            'INSERT INTO ecotone_projection_state (projection_name, partition_key, last_position, user_state, metadata) VALUES (?, ?, ?, ?, ?)',
            [$projectionName, '', $combinedPosition, 'null', '{"initialization_status":"initialized"}'],
        );
    }

    private function bootstrapEcotone(array $classesToResolve, array $services, bool|array $channels = false, ?TestConfiguration $testConfiguration = null, ?string $licenceKey = LicenceTesting::VALID_LICENCE): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [...$classesToResolve, CalendarWithInternalRecorder::class, MeetingWithEventSourcing::class, EventsConverter::class],
            containerOrAvailableServices: [...$services, new EventsConverter(), self::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ])),
            runForProductionEventStore: true,
            enableAsynchronousProcessing: $channels,
            licenceKey: $licenceKey,
            testConfiguration: $testConfiguration,
        );
    }
}

final class ProjectedEventsRecorder
{
    /** @var array<string, int> */
    public array $projected = [];
    /** @var array<string, int> */
    public array $stateSeenBy = [];
    public int $resets = 0;

    public function record(string $event): void
    {
        $this->projected[$event] = ($this->projected[$event] ?? 0) + 1;
    }
}

abstract class RecordingCalendarProjection
{
    public function __construct(protected ProjectedEventsRecorder $recorder)
    {
    }

    #[EventHandler]
    public function whenCalendarCreated(CalendarCreated $event): void
    {
        $this->recorder->record("calendar_created:{$event->calendarId}");
    }

    #[EventHandler]
    public function whenMeetingScheduled(MeetingScheduled $event): void
    {
        $this->recorder->record("meeting_scheduled:{$event->meetingId}");
    }

    #[EventHandler]
    public function whenMeetingCreated(MeetingCreated $event): void
    {
        $this->recorder->record("meeting_created:{$event->meetingId}");
    }

    #[ProjectionReset]
    public function reset(): void
    {
        $this->recorder->resets++;
        $this->recorder->projected = [];
    }
}

abstract class StateCountingCalendarProjection
{
    public function __construct(protected ProjectedEventsRecorder $recorder)
    {
    }

    #[EventHandler]
    public function whenCalendarCreated(CalendarCreated $event, #[ProjectionState] int $handledEvents = 0): int
    {
        $this->recorder->stateSeenBy["calendar_created:{$event->calendarId}"] = $handledEvents;
        return $handledEvents + 1;
    }

    #[EventHandler]
    public function whenMeetingScheduled(MeetingScheduled $event, #[ProjectionState] int $handledEvents = 0): int
    {
        $this->recorder->stateSeenBy["meeting_scheduled:{$event->meetingId}"] = $handledEvents;
        return $handledEvents + 1;
    }

    #[EventHandler]
    public function whenMeetingCreated(MeetingCreated $event, #[ProjectionState] int $handledEvents = 0): int
    {
        $this->recorder->stateSeenBy["meeting_created:{$event->meetingId}"] = $handledEvents;
        return $handledEvents + 1;
    }
}

interface ParallelProjectionStateGateway
{
    #[ProjectionStateGateway('parallel_with_state_gateway')]
    public function fetchState(): int;
}
