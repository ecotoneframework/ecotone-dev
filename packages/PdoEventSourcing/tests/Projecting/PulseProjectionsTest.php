<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Polling;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Partitioned;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionBackfill;
use Ecotone\Api\Projecting\ProjectionDeployment;
use Ecotone\Api\Projecting\ProjectionRebuild;
use Ecotone\Api\Projecting\Streaming;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\ChangeAssignedPerson;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\AssignedPersonWasChanged;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

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
final class PulseProjectionsTest extends EventSourcingMessagingTestCase
{
    private object $pulse;
    private FlowTestSupport $pulseService;
    private FlowTestSupport $orderService;

    public static function storages(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'dbal' => ['dbal'];
    }

    #[DataProvider('storages')]
    public function test_the_snapshot_lists_every_projection_with_its_mode_partitioning_and_initialization(string $storage): void
    {
        $synchronous = new #[Projection('tickets_sync'), FromAggregateStream(Ticket::class)] class () {
            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
            }
        };
        $asynchronous = new #[Projection('tickets_async'), Asynchronous('tickets_async_channel'), FromAggregateStream(Ticket::class)] class () {
            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
            }
        };
        $partitioned = new #[Projection('tickets_partitioned'), Partitioned, ProjectionRebuild(asyncChannelName: 'tickets_rebuild_channel'), ProjectionBackfill(asyncChannelName: 'tickets_backfill_channel'), FromAggregateStream(Ticket::class)] class () {
            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
            }
        };
        $polling = new #[Projection('tickets_polling'), Polling('tickets_polling_runner'), FromAggregateStream(Ticket::class)] class () {
            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
            }
        };
        $this->bootstrap($storage, [$synchronous, $asynchronous, $partitioned, $polling]);

        $before = $this->requestSnapshot()['projections'];
        $this->orderService->sendCommand(new RegisterTicket('ticket-1', 'John', 'bug'));
        $after = $this->requestSnapshot()['projections'];

        self::assertSame(
            [
                ['name' => 'tickets_async', 'mode' => 'asynchronous', 'partitioned' => false, 'asyncChannel' => 'tickets_async_channel', 'rebuildAsyncChannel' => null, 'backfillAsyncChannel' => null],
                ['name' => 'tickets_partitioned', 'mode' => 'synchronous', 'partitioned' => true, 'asyncChannel' => null, 'rebuildAsyncChannel' => 'tickets_rebuild_channel', 'backfillAsyncChannel' => 'tickets_backfill_channel'],
                ['name' => 'tickets_polling', 'mode' => 'polling', 'partitioned' => false, 'asyncChannel' => null, 'rebuildAsyncChannel' => null, 'backfillAsyncChannel' => null],
                ['name' => 'tickets_sync', 'mode' => 'synchronous', 'partitioned' => false, 'asyncChannel' => null, 'rebuildAsyncChannel' => null, 'backfillAsyncChannel' => null],
            ],
            array_map(fn (array $projection): array => array_intersect_key($projection, array_flip(['name', 'mode', 'partitioned', 'asyncChannel', 'rebuildAsyncChannel', 'backfillAsyncChannel'])), $before),
        );
        self::assertSame(
            ['tickets_async' => 'uninitialized', 'tickets_partitioned' => 'uninitialized', 'tickets_polling' => 'uninitialized', 'tickets_sync' => 'uninitialized'],
            array_column($before, 'initialization', 'name'),
        );
        self::assertSame('initialized', array_column($after, 'initialization', 'name')['tickets_sync']);
    }

    #[DataProvider('storages')]
    public function test_a_service_without_projections_reports_an_empty_projections_list(string $storage): void
    {
        $this->bootstrap($storage, []);

        self::assertSame([], $this->requestSnapshot()['projections']);
    }

    #[DataProvider('storages')]
    public function test_events_appended_and_not_yet_projected_show_as_events_behind_until_the_projection_runs(string $storage): void
    {
        $projection = new #[Projection('tickets_async'), Asynchronous('tickets_async_channel'), FromAggregateStream(Ticket::class)] class () {
            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
            }

            #[EventHandler]
            public function whenAssigned(AssignedPersonWasChanged $event): void
            {
            }
        };
        $this->bootstrap($storage, [$projection]);

        $this->orderService->sendCommand(new RegisterTicket('ticket-1', 'John', 'bug'));
        $this->orderService->sendCommand(new RegisterTicket('ticket-2', 'Jane', 'feature'));
        $this->orderService->sendCommand(new ChangeAssignedPerson('ticket-1', 'Mark'));
        $behind = $this->requestSnapshot()['projections'][0];

        $this->orderService->run('tickets_async_channel', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 3));
        $caughtUp = $this->requestSnapshot()['projections'][0];

        self::assertSame(['events' => 3, 'atLeast' => false], $behind['eventsBehind']);
        self::assertSame(['events' => 0, 'atLeast' => false], $caughtUp['eventsBehind']);
        self::assertSame('initialized', $caughtUp['initialization']);
    }

    #[DataProvider('storages')]
    public function test_events_behind_is_capped_and_says_at_least_when_more_remain(string $storage): void
    {
        $projection = new #[Projection('tickets_async'), Asynchronous('tickets_async_channel'), FromAggregateStream(Ticket::class)] class () {
            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
            }

            #[EventHandler]
            public function whenAssigned(AssignedPersonWasChanged $event): void
            {
            }
        };
        $this->bootstrap($storage, [$projection]);

        for ($ticket = 1; $ticket <= 1001; $ticket++) {
            $this->orderService->sendCommand(new RegisterTicket("ticket-{$ticket}", 'John', 'bug'));
        }

        self::assertSame(['events' => 1000, 'atLeast' => true], $this->requestSnapshot()['projections'][0]['eventsBehind']);
    }

    public function test_partitioned_projection_reports_total_partitions_and_how_many_are_behind(): void
    {
        $storage = 'dbal';
        $projection = new #[Projection('tickets_partitioned'), Partitioned, Asynchronous('tickets_partitioned_channel'), FromAggregateStream(Ticket::class)] class () {
            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
            }

            #[EventHandler]
            public function whenAssigned(AssignedPersonWasChanged $event): void
            {
            }
        };
        $this->bootstrap($storage, [$projection]);

        $this->orderService->sendCommand(new RegisterTicket('ticket-1', 'John', 'bug'));
        $this->orderService->sendCommand(new RegisterTicket('ticket-2', 'Jane', 'feature'));
        $behind = $this->requestSnapshot()['projections'][0];

        $this->orderService->run('tickets_partitioned_channel', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 2));
        $this->orderService->sendCommand(new ChangeAssignedPerson('ticket-1', 'Mark'));
        $oneBehind = $this->requestSnapshot()['projections'][0];

        self::assertSame(['total' => 2, 'inspected' => 2, 'behind' => 2, 'atLeast' => false], $behind['partitions']);
        self::assertSame(['total' => 2, 'inspected' => 2, 'behind' => 1, 'atLeast' => false], $oneBehind['partitions']);
        self::assertNull($behind['eventsBehind']);
    }

    #[DataProvider('storages')]
    public function test_the_position_digest_changes_only_when_the_projection_moves(string $storage): void
    {
        $projection = new #[Projection('tickets_async'), Asynchronous('tickets_async_channel'), FromAggregateStream(Ticket::class)] class () {
            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
            }
        };
        $this->bootstrap($storage, [$projection]);

        $this->orderService->sendCommand(new RegisterTicket('ticket-1', 'John', 'bug'));
        $initial = $this->requestSnapshot()['projections'][0]['positionDigest'];
        $this->orderService->sendCommand(new RegisterTicket('ticket-2', 'Jane', 'bug'));
        $eventsAppendedProjectionIdle = $this->requestSnapshot()['projections'][0]['positionDigest'];
        $this->orderService->run('tickets_async_channel', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 2));
        $afterRun = $this->requestSnapshot()['projections'][0]['positionDigest'];

        self::assertSame($initial, $eventsAppendedProjectionIdle);
        self::assertNotSame($initial, $afterRun);
    }

    #[DataProvider('storages')]
    public function test_a_streaming_projection_is_reported_with_the_reason_its_position_cannot_be_read(string $storage): void
    {
        $projection = new #[Projection('tickets_streaming'), Streaming('tickets_stream')] class () {
            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
            }
        };
        $this->bootstrap($storage, [$projection], extensionObjects: [SimpleMessageChannelBuilder::createStreamingChannel('tickets_stream', conversionMediaType: MediaType::createApplicationXPHP())]);

        $streaming = $this->requestSnapshot()['projections'][0];

        self::assertSame('tickets_streaming', $streaming['name']);
        self::assertSame('streaming', $streaming['mode']);
        self::assertFalse($streaming['partitioned']);
        self::assertSame('tickets_stream', $streaming['asyncChannel']);
        self::assertNull($streaming['eventsBehind']);
        self::assertSame('A streaming projection keeps no position of its own: it consumes the channel tickets_stream, so how far it is behind is the depth of that channel, which the channels section reports.', $streaming['unavailable']);
    }

    public function test_partitions_inspected_are_capped_and_behind_says_at_least_when_more_remain(): void
    {
        $projection = new #[Projection('tickets_partitioned'), Partitioned, Asynchronous('tickets_partitioned_channel'), FromAggregateStream(Ticket::class)] class () {
            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
            }
        };
        $this->bootstrap('dbal', [$projection]);

        for ($ticket = 1; $ticket <= 101; $ticket++) {
            $this->orderService->sendCommand(new RegisterTicket("ticket-{$ticket}", 'John', 'bug'));
        }

        self::assertSame(['total' => 101, 'inspected' => 100, 'behind' => 100, 'atLeast' => true], $this->requestSnapshot()['projections'][0]['partitions']);
    }

    public function test_projections_of_a_multi_tenant_service_are_left_out_of_the_snapshot_without_a_tenant(): void
    {
        $this->bootstrap('dbal', [$this->asynchronousProjection()], multiTenant: true);

        $snapshot = $this->requestSnapshot();

        self::assertSame(['header' => 'tenant', 'tenants' => ['tenant_a', 'tenant_b']], $snapshot['tenancy']);
        self::assertArrayNotHasKey('projections', $snapshot);
    }

    public function test_projections_of_a_multi_tenant_service_are_reported_from_the_event_store_of_the_tenant_asked(): void
    {
        $this->bootstrap('dbal', [$this->asynchronousProjection()], multiTenant: true);
        $this->orderService->sendCommand(new RegisterTicket('ticket-1', 'Johny', 'alert'), metadata: ['tenant' => 'tenant_a']);

        $ofTenantA = $this->requestSnapshot('tenant_a');
        $ofTenantB = $this->requestSnapshot('tenant_b');

        self::assertSame('tenant_a', $ofTenantA['tenant']);
        self::assertSame('tickets_async', $ofTenantA['projections'][0]['name']);
        self::assertNull($ofTenantA['projections'][0]['unavailable']);
        self::assertSame(['events' => 1, 'atLeast' => false], $ofTenantA['projections'][0]['eventsBehind']);
        self::assertSame(['events' => 0, 'atLeast' => false], $ofTenantB['projections'][0]['eventsBehind']);
    }

    public function test_initializing_and_backfilling_from_pulse_set_up_a_projection_on_the_event_store_of_the_service(): void
    {
        $initialized = new #[Projection('tickets_kick_off_initialized'), ProjectionDeployment(manualKickOff: true), FromAggregateStream(Ticket::class)] class () {
            public int $handled = 0;

            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
                $this->handled++;
            }
        };
        $backfilled = new #[Projection('tickets_kick_off_backfilled'), ProjectionDeployment(manualKickOff: true), FromAggregateStream(Ticket::class)] class () {
            public int $handled = 0;

            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
                $this->handled++;
            }
        };
        $this->bootstrap('dbal', [$initialized, $backfilled]);
        $this->orderService->sendCommand(new RegisterTicket('ticket-1', 'Johny', 'alert'));
        self::assertSame(['tickets_kick_off_backfilled' => 'uninitialized', 'tickets_kick_off_initialized' => 'uninitialized'], array_column($this->requestSnapshot()['projections'], 'initialization', 'name'));

        $this->act('ecotone.pulse.projections.initialize', 'tickets_kick_off_initialized', '0af7651916cd43dd8448eb211c80319c');
        $this->act('ecotone.pulse.projections.backfill', 'tickets_kick_off_backfilled', '1af7651916cd43dd8448eb211c80319c');

        self::assertSame(['tickets_kick_off_backfilled' => 'initialized', 'tickets_kick_off_initialized' => 'initialized'], array_column($this->requestSnapshot()['projections'], 'initialization', 'name'));
        self::assertSame(1, $initialized->handled);
        self::assertSame(1, $backfilled->handled);
    }

    public function test_initializing_a_projection_in_one_tenant_leaves_it_uninitialized_in_the_other(): void
    {
        $this->bootstrap('dbal', [new #[Projection('tickets_kick_off'), ProjectionDeployment(manualKickOff: true), FromAggregateStream(Ticket::class)] class () {
            public int $handled = 0;

            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
                $this->handled++;
            }
        }], multiTenant: true);
        $this->orderService->sendCommand(new RegisterTicket('ticket-1', 'Johny', 'alert'), metadata: ['tenant' => 'tenant_a']);

        $this->act('ecotone.pulse.projections.initialize', 'tickets_kick_off', '0af7651916cd43dd8448eb211c80319c', 'tenant_a');

        self::assertSame('initialized', $this->requestSnapshot('tenant_a')['projections'][0]['initialization']);
        self::assertSame('uninitialized', $this->requestSnapshot('tenant_b')['projections'][0]['initialization']);
    }

    public function test_backfilling_a_projection_in_one_tenant_leaves_it_uninitialized_in_the_other(): void
    {
        $this->bootstrap('dbal', [new #[Projection('tickets_kick_off'), ProjectionDeployment(manualKickOff: true), FromAggregateStream(Ticket::class)] class () {
            public int $handled = 0;

            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
                $this->handled++;
            }
        }], multiTenant: true);
        $this->orderService->sendCommand(new RegisterTicket('ticket-1', 'Johny', 'alert'), metadata: ['tenant' => 'tenant_b']);

        $this->act('ecotone.pulse.projections.backfill', 'tickets_kick_off', '0af7651916cd43dd8448eb211c80319c', 'tenant_b');

        self::assertSame('initialized', $this->requestSnapshot('tenant_b')['projections'][0]['initialization']);
        self::assertSame('uninitialized', $this->requestSnapshot('tenant_a')['projections'][0]['initialization']);
    }

    private function asynchronousProjection(): object
    {
        return new #[Projection('tickets_async'), Asynchronous('tickets_async_channel'), FromAggregateStream(Ticket::class)] class () {
            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
            }
        };
    }

    private function bootstrap(string $storage, array $projections, bool $multiTenant = false, array $extensionObjects = []): void
    {
        $this->pulse = new class () {
            public array $snapshots = [];

            #[InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
                $this->snapshots[] = $snapshot;
            }
        };
        $channels = [
            SimpleMessageChannelBuilder::createQueueChannel('order_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('pulse_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('tickets_async_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('tickets_partitioned_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('tickets_backfill_channel'),
        ];
        $dbal = $storage === 'dbal';
        $this->orderService = $this->bootstrapFlowTesting(
            classesToResolve: [...array_map(fn (object $projection): string => $projection::class, $projections), Ticket::class, TicketEventConverter::class],
            containerOrAvailableServices: [
                ...$projections,
                new TicketEventConverter(),
                ...($multiTenant ? ['tenant_a_connection' => $this->connectionForTenantA(), 'tenant_b_connection' => $this->connectionForTenantB()] : []),
                ...($dbal && ! $multiTenant ? [DbalConnectionFactory::class => self::getConnectionFactory()] : []),
            ],
            extensionObjects: [
                ...$channels,
                ...$extensionObjects,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
                ...($multiTenant ? [DbalConfiguration::createForTesting(), MultiTenantConfiguration::create('tenant', ['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_b_connection'])] : []),
            ],
            enabledModules: $dbal ? [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE] : [ModulePackageList::EVENT_SOURCING_PACKAGE],
            addInMemoryEventSourcedRepository: ! $dbal,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $this->pulseService = $this->bootstrapFlowTesting(
            classesToResolve: [$this->pulse::class],
            containerOrAvailableServices: [$this->pulse],
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ecotone_pulse'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function act(string $routingKey, string $projection, string $tracingId, ?string $tenant = null): void
    {
        $this->pulseService->getDistributedBus()->sendMessage('order_service', $routingKey, json_encode(['projection' => $projection], JSON_THROW_ON_ERROR), MediaType::APPLICATION_JSON, metadata: [MessageHeaders::TRACING_ID => $tracingId, ...($tenant === null ? [] : ['tenant' => $tenant])], replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());
    }

    private function requestSnapshot(?string $tenant = null): array
    {
        $this->pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, metadata: $tenant === null ? [] : ['tenant' => $tenant], replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());

        return json_decode(array_pop($this->pulse->snapshots), true, flags: JSON_THROW_ON_ERROR);
    }
}
