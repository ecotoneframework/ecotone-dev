<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Partitioned;

use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\FromTaggedStream;
use Ecotone\Api\Projecting\Partitioned;
use Ecotone\Api\Projecting\PartitionTagValue;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionDelete;
use Ecotone\Api\Projecting\ProjectionExecution;
use Ecotone\Api\Projecting\ProjectionReset;
use Ecotone\Api\Projecting\ProjectionState;
use Ecotone\Api\Projecting\ProjectionStateGateway;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Test\Ecotone\EventSourcing\Projecting\ProjectingTestCase;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class TagPartitionedProjectionTest extends ProjectingTestCase
{
    public static function implementations(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'in-memory-without-event-sourcing-module' => ['in-memory-without-event-sourcing-module'];
        yield 'dbal' => ['dbal'];
    }

    #[DataProvider('implementations')]
    public function test_a_projection_partitioned_by_a_tag_keeps_its_state_per_value_of_the_tag(string $implementation): void
    {
        $projection = $this->concertSeatsProjection();
        $ecotone = $this->bootstrap($implementation, [$projection]);

        $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-1', 'A1'));
        $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-2', 'B1'));
        $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-1', 'A2'));

        self::assertSame(['concert-1' => ['A1', 'A2'], 'concert-2' => ['B1']], $ecotone->sendQueryWithRouting('concert.reservedSeats'));
        self::assertSame(['concert-1' => 2, 'concert-2' => 1], $ecotone->sendQueryWithRouting('concert.reservationsCountedInState'));
    }

    #[DataProvider('implementations')]
    public function test_a_partition_longer_than_one_loading_batch_is_rebuilt_page_by_page(string $implementation): void
    {
        $projection = $this->concertSeatsProjection();
        $ecotone = $this->bootstrap($implementation, [$projection]);
        foreach (['A1', 'A2', 'A3', 'A4', 'A5'] as $seatId) {
            $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-1', $seatId));
        }

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'concert_seats']);

        self::assertSame(['concert-1' => ['A1', 'A2', 'A3', 'A4', 'A5']], $ecotone->sendQueryWithRouting('concert.reservedSeats'));
        self::assertSame(['concert-1' => 5], $ecotone->sendQueryWithRouting('concert.reservationsCountedInState'));
    }

    #[DataProvider('implementations')]
    public function test_triggering_a_deleted_projection_replays_every_value_of_the_tag(string $implementation): void
    {
        $projection = $this->concertSeatsProjection();
        $ecotone = $this->bootstrap($implementation, [$projection]);
        $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-1', 'A1'));
        $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-2', 'B1'));
        $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-1', 'A2'));

        $ecotone->deleteProjection('concert_seats');
        self::assertSame([], $ecotone->sendQueryWithRouting('concert.reservedSeats'));

        $ecotone->initializeProjection('concert_seats')->triggerProjection('concert_seats');

        self::assertSame(['concert-1' => ['A1', 'A2'], 'concert-2' => ['B1']], $ecotone->sendQueryWithRouting('concert.reservedSeats'));
        self::assertSame(['concert-1' => 2, 'concert-2' => 1], $ecotone->sendQueryWithRouting('concert.reservationsCountedInState'));
    }

    #[DataProvider('implementations')]
    public function test_an_event_carrying_several_values_of_the_tag_reaches_each_of_their_partitions_once(string $implementation): void
    {
        $projection = $this->concertSeatsProjection();
        $ecotone = $this->bootstrap($implementation, [$projection]);

        $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-1', 'A1'));
        $ecotone->sendCommand(new SellFestivalPassForTaggedProjection('concert-1', ['concert-1', 'concert-2'], 'pass-1'));

        self::assertSame(['concert-1' => ['A1', 'pass-1'], 'concert-2' => ['pass-1']], $ecotone->sendQueryWithRouting('concert.reservedSeats'));
        self::assertSame(['concert-1' => 2, 'concert-2' => 1], $ecotone->sendQueryWithRouting('concert.reservationsCountedInState'));
    }

    #[DataProvider('implementations')]
    public function test_rebuilding_resets_and_replays_each_value_of_the_tag_on_its_own(string $implementation): void
    {
        $projection = $this->concertSeatsProjection();
        $ecotone = $this->bootstrap($implementation, [$projection]);
        $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-1', 'A1'));
        $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-2', 'B1'));
        $ecotone->sendCommand(new SellFestivalPassForTaggedProjection('concert-2', ['concert-1', 'concert-2'], 'pass-1'));

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'concert_seats']);

        self::assertSame(['concert-1' => ['A1', 'pass-1'], 'concert-2' => ['B1', 'pass-1']], $ecotone->sendQueryWithRouting('concert.reservedSeats'));
        self::assertSame(['concert-1' => 2, 'concert-2' => 2], $ecotone->sendQueryWithRouting('concert.reservationsCountedInState'));
        self::assertSame(['concert-1', 'concert-2'], $ecotone->sendQueryWithRouting('concert.resetConcerts'));
    }

    #[DataProvider('implementations')]
    public function test_backfilling_a_deleted_projection_projects_every_value_of_the_tag(string $implementation): void
    {
        $projection = $this->concertSeatsProjection();
        $ecotone = $this->bootstrap($implementation, [$projection]);
        $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-1', 'A1'));
        $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-2', 'B1'));
        $ecotone->deleteProjection('concert_seats');

        $ecotone->initializeProjection('concert_seats');
        $ecotone->runConsoleCommand('ecotone:projection:backfill', ['name' => 'concert_seats']);

        self::assertSame(['concert-1' => ['A1'], 'concert-2' => ['B1']], $ecotone->sendQueryWithRouting('concert.reservedSeats'));
    }

    #[DataProvider('implementations')]
    public function test_an_asynchronous_projection_partitioned_by_a_tag_projects_once_its_channel_runs(string $implementation): void
    {
        $seats = $this->concertSeatsProjection();
        $sales = $this->concertSalesProjection();
        $ecotone = $this->bootstrap(
            $implementation,
            [$seats, $sales],
            [SimpleMessageChannelBuilder::createQueueChannel('concert_projections', conversionMediaType: MediaType::createApplicationXPHPSerialized())],
        );

        $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-1', 'A1'));
        $ecotone->sendCommand(new SellFestivalPassForTaggedProjection('concert-1', ['concert-1', 'concert-2'], 'pass-1'));

        self::assertSame(['concert-1' => ['A1', 'pass-1'], 'concert-2' => ['pass-1']], $ecotone->sendQueryWithRouting('concert.reservedSeats'));
        self::assertSame([], $ecotone->sendQueryWithRouting('concert.sales'));

        $ecotone->run('concert_projections', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['concert-1' => 2, 'concert-2' => 1], $ecotone->sendQueryWithRouting('concert.sales'));
    }

    public static function implementationsWithEventSourcingModule(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'dbal' => ['dbal'];
    }

    #[DataProvider('implementationsWithEventSourcingModule')]
    public function test_a_projection_state_gateway_reads_the_state_of_one_value_of_the_tag(string $implementation): void
    {
        $projection = $this->concertSeatsProjection();
        $ecotone = $this->bootstrap($implementation, [$projection], classesToResolve: [ConcertReservationsStateForTaggedProjection::class]);

        $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-1', 'A1'));
        $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-1', 'A2'));
        $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-2', 'B1'));

        $state = $ecotone->getGateway(ConcertReservationsStateForTaggedProjection::class);
        self::assertSame(['reservations' => 2], $state->forConcert('concert-1'));
        self::assertSame(['reservations' => 1], $state->forConcert('concert-2'));
        self::assertSame([], $state->forConcert('concert-404'));
    }

    #[DataProvider('implementations')]
    public function test_resetting_one_of_two_projections_over_a_tag_leaves_the_other_untouched(string $implementation): void
    {
        $seats = $this->concertSeatsProjection();
        $sales = $this->concertSalesProjection();
        $ecotone = $this->bootstrap($implementation, [$seats, $sales], [SimpleMessageChannelBuilder::createQueueChannel('concert_projections')]);
        $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-1', 'A1'));
        $ecotone->sendCommand(new ReserveSeatForTaggedProjection('concert-2', 'B1'));
        $ecotone->run('concert_projections', ExecutionPollingMetadata::createWithTestingSetup());

        $ecotone->resetProjection('concert_sales');

        self::assertSame(['concert-1' => 1, 'concert-2' => 1], $ecotone->sendQueryWithRouting('concert.sales'));
        self::assertSame(['concert-1' => ['A1'], 'concert-2' => ['B1']], $ecotone->sendQueryWithRouting('concert.reservedSeats'));
        self::assertSame([], $ecotone->sendQueryWithRouting('concert.resetConcerts'));
    }

    #[DataProvider('implementations')]
    public function test_pulse_reports_the_partitions_of_a_tag_partitioned_projection_and_rebuilds_it(string $implementation): void
    {
        $pulse = new class () {
            public array $snapshots = [];

            #[InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
                $this->snapshots[] = $snapshot;
            }
        };
        $channels = [
            SimpleMessageChannelBuilder::createQueueChannel('concert_service_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('pulse_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('concert_projections'),
        ];
        $sales = $this->concertSalesProjection();
        $concertService = $this->bootstrap($implementation, [$sales], [
            ...$channels,
            DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
            ServiceConfiguration::createWithDefaults()->withServiceName('concert_service'),
            PulseConfiguration::createWithDefaults('ecotone_pulse'),
        ]);
        $pulseService = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$pulse::class],
            containerOrAvailableServices: [$pulse],
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'concert_service', channelName: 'concert_service_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ecotone_pulse'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $askPulse = function (string $routingKey, string $payload) use ($pulse, $pulseService, $concertService): array {
            $pulseService->getDistributedBus()->sendMessage('concert_service', $routingKey, $payload, MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
            $concertService->run('concert_service_channel', ExecutionPollingMetadata::createWithTestingSetup());
            $pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());

            return json_decode(array_pop($pulse->snapshots), true, flags: JSON_THROW_ON_ERROR);
        };

        $concertService->sendCommand(new ReserveSeatForTaggedProjection('concert-1', 'A1'));
        $concertService->sendCommand(new ReserveSeatForTaggedProjection('concert-2', 'B1'));
        $behind = $askPulse('ecotone.pulse.snapshot', '{}')['projections'][0];
        $concertService->run('concert_projections', ExecutionPollingMetadata::createWithTestingSetup());
        $caughtUp = $askPulse('ecotone.pulse.snapshot', '{}')['projections'][0];

        self::assertSame(['concert_sales', true], [$behind['name'], $behind['partitioned']]);
        self::assertSame(['total' => 2, 'inspected' => 2, 'behind' => 2, 'atLeast' => false], $behind['partitions']);
        self::assertSame(['total' => 2, 'inspected' => 2, 'behind' => 0, 'atLeast' => false], $caughtUp['partitions']);

        $askPulse('ecotone.pulse.projections.rebuild', json_encode(['projection' => 'concert_sales'], JSON_THROW_ON_ERROR));

        self::assertSame(['concert-1' => 1, 'concert-2' => 1], $concertService->sendQueryWithRouting('concert.sales'));
    }

    private function concertSalesProjection(): object
    {
        return new #[Projection('concert_sales'), FromTaggedStream('concert'), Partitioned, Asynchronous('concert_projections')] class () {
            public array $sales = [];

            #[EventHandler]
            public function whenReserved(SeatReservedForTaggedProjection $event, #[PartitionTagValue] string $concertId): void
            {
                $this->sales[$concertId] = ($this->sales[$concertId] ?? 0) + 1;
            }

            #[EventHandler]
            public function whenPassSold(FestivalPassSoldForTaggedProjection $event, #[PartitionTagValue] string $concertId): void
            {
                $this->sales[$concertId] = ($this->sales[$concertId] ?? 0) + 1;
            }

            #[QueryHandler('concert.sales')]
            public function sales(): array
            {
                ksort($this->sales);

                return $this->sales;
            }

            #[ProjectionReset]
            public function reset(#[PartitionTagValue] string $concertId): void
            {
                unset($this->sales[$concertId]);
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->sales = [];
            }
        };
    }

    private function concertSeatsProjection(): object
    {
        return new #[Projection('concert_seats'), FromTaggedStream('concert'), Partitioned, ProjectionExecution(eventLoadingBatchSize: 2)] class () {
            public array $reservedSeats = [];
            public array $reservationsCountedInState = [];
            public array $resetConcerts = [];

            #[EventHandler]
            public function whenReserved(SeatReservedForTaggedProjection $event, #[ProjectionState] array $state = []): array
            {
                $this->reservedSeats[$event->concertId][] = $event->seatId;
                $state['reservations'] = ($state['reservations'] ?? 0) + 1;
                $this->reservationsCountedInState[$event->concertId] = $state['reservations'];

                return $state;
            }

            #[EventHandler]
            public function whenReleased(SeatReleasedForTaggedProjection $event, #[ProjectionState] array $state = []): array
            {
                $this->reservedSeats[$event->concertId] = array_values(array_diff($this->reservedSeats[$event->concertId] ?? [], [$event->seatId]));
                $state['reservations'] = ($state['reservations'] ?? 0) - 1;
                $this->reservationsCountedInState[$event->concertId] = $state['reservations'];

                return $state;
            }

            #[EventHandler]
            public function whenPassSold(FestivalPassSoldForTaggedProjection $event, #[PartitionTagValue] string $concertId, #[ProjectionState] array $state = []): array
            {
                $this->reservedSeats[$concertId][] = $event->passId;
                $state['reservations'] = ($state['reservations'] ?? 0) + 1;
                $this->reservationsCountedInState[$concertId] = $state['reservations'];

                return $state;
            }

            #[QueryHandler('concert.resetConcerts')]
            public function resetConcerts(): array
            {
                sort($this->resetConcerts);

                return $this->resetConcerts;
            }

            #[QueryHandler('concert.reservedSeats')]
            public function reservedSeats(): array
            {
                ksort($this->reservedSeats);

                return $this->reservedSeats;
            }

            #[QueryHandler('concert.reservationsCountedInState')]
            public function reservationsCountedInState(): array
            {
                ksort($this->reservationsCountedInState);

                return $this->reservationsCountedInState;
            }

            #[ProjectionReset]
            public function reset(#[PartitionTagValue] string $concertId): void
            {
                $this->resetConcerts[] = $concertId;
                unset($this->reservedSeats[$concertId], $this->reservationsCountedInState[$concertId]);
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->reservedSeats = [];
                $this->reservationsCountedInState = [];
            }
        };
    }

    private function bootstrap(string $implementation, array $projections, array $extensionObjects = [], array $classesToResolve = []): FlowTestSupport
    {
        $seatReservations = new class () {
            #[CommandHandler]
            public function reserve(ReserveSeatForTaggedProjection $command, ConcertSeatingForTaggedProjection $concert): array
            {
                if ($concert->isTaken($command->seatId)) {
                    throw new RuntimeException("Seat {$command->seatId} of {$command->concertId} is already taken");
                }

                return [new SeatReservedForTaggedProjection($command->concertId, $command->seatId)];
            }

            #[CommandHandler]
            public function sellPass(SellFestivalPassForTaggedProjection $command, ConcertSeatingForTaggedProjection $concert): array
            {
                return [new FestivalPassSoldForTaggedProjection($command->concertIds, $command->passId)];
            }

            #[CommandHandler]
            public function release(ReleaseSeatForTaggedProjection $command, ConcertSeatingForTaggedProjection $concert): array
            {
                return $concert->isTaken($command->seatId) ? [new SeatReleasedForTaggedProjection($command->concertId, $command->seatId)] : [];
            }
        };
        $converter = new class () {
            #[Converter]
            public function fromReserved(SeatReservedForTaggedProjection $event): array
            {
                return ['concertId' => $event->concertId, 'seatId' => $event->seatId];
            }

            #[Converter]
            public function toReserved(array $event): SeatReservedForTaggedProjection
            {
                return new SeatReservedForTaggedProjection($event['concertId'], $event['seatId']);
            }

            #[Converter]
            public function fromPassSold(FestivalPassSoldForTaggedProjection $event): array
            {
                return ['concertIds' => $event->concertIds, 'passId' => $event->passId];
            }

            #[Converter]
            public function toPassSold(array $event): FestivalPassSoldForTaggedProjection
            {
                return new FestivalPassSoldForTaggedProjection($event['concertIds'], $event['passId']);
            }

            #[Converter]
            public function fromReleased(SeatReleasedForTaggedProjection $event): array
            {
                return ['concertId' => $event->concertId, 'seatId' => $event->seatId];
            }

            #[Converter]
            public function toReleased(array $event): SeatReleasedForTaggedProjection
            {
                return new SeatReleasedForTaggedProjection($event['concertId'], $event['seatId']);
            }
        };

        $classesToResolve = [
            ...array_map(fn (object $projection): string => $projection::class, $projections),
            $seatReservations::class,
            $converter::class,
            ConcertSeatingForTaggedProjection::class,
            SeatReservedForTaggedProjection::class,
            SeatReleasedForTaggedProjection::class,
            FestivalPassSoldForTaggedProjection::class,
            ...$classesToResolve,
        ];
        $services = [...$projections, $seatReservations, $converter];

        if ($implementation === 'in-memory-without-event-sourcing-module') {
            return EcotoneLite::bootstrapFlowTesting(
                classesToResolve: $classesToResolve,
                containerOrAvailableServices: $services,
                extensionObjects: [DynamicConsistencyBoundaryConfiguration::createWithDefaults(), ...$extensionObjects],
                licenceKey: LicenceTesting::VALID_LICENCE,
            );
        }

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: $classesToResolve,
            containerOrAvailableServices: [...$services, DbalConnectionFactory::class => self::getConnectionFactory()],
            extensionObjects: [
                DynamicConsistencyBoundaryConfiguration::createWithDefaults(),
                ...$extensionObjects,
                $implementation === 'in-memory' ? EventSourcingConfiguration::createInMemory() : EventSourcingConfiguration::createWithDefaults(),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}

interface ConcertReservationsStateForTaggedProjection
{
    #[ProjectionStateGateway('concert_seats')]
    public function forConcert(string $concertId): array;
}

final readonly class ReserveSeatForTaggedProjection
{
    public function __construct(
        public string $concertId,
        public string $seatId,
    ) {
    }
}

final readonly class ReleaseSeatForTaggedProjection
{
    public function __construct(
        public string $concertId,
        public string $seatId,
    ) {
    }
}

final readonly class SellFestivalPassForTaggedProjection
{
    /**
     * @param string[] $concertIds
     */
    public function __construct(
        public string $concertId,
        public array $concertIds,
        public string $passId,
    ) {
    }
}

final readonly class FestivalPassSoldForTaggedProjection
{
    /**
     * @param string[] $concertIds
     */
    public function __construct(
        #[EventTag('concert')] public array $concertIds,
        public string $passId,
    ) {
    }
}

final readonly class SeatReservedForTaggedProjection
{
    public function __construct(
        #[EventTag('concert')] public string $concertId,
        public string $seatId,
    ) {
    }
}

final readonly class SeatReleasedForTaggedProjection
{
    public function __construct(
        #[EventTag('concert')] public string $concertId,
        public string $seatId,
    ) {
    }
}

#[DecisionModel]
final class ConcertSeatingForTaggedProjection
{
    private array $takenSeats = [];

    #[Apply]
    public function reserved(SeatReservedForTaggedProjection $event): void
    {
        $this->takenSeats[$event->seatId] = true;
    }

    #[Apply]
    public function released(SeatReleasedForTaggedProjection $event): void
    {
        unset($this->takenSeats[$event->seatId]);
    }

    public function isTaken(string $seatId): bool
    {
        return isset($this->takenSeats[$seatId]);
    }
}
