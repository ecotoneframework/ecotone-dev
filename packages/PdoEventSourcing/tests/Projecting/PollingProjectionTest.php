<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Projecting\Attribute\Polling;
use Ecotone\Projecting\Attribute\Projection;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * licence Apache-2.0
 * @internal
 */
final class PollingProjectionTest extends ProjectingTestCase
{
    public function test_polling_projection_with_global_stream(): void
    {
        // Given a polling projection
        $projection = new #[Projection('polling_test'), Polling('polling_test_runner'), FromStream(Ticket::class)] class {
            public array $projectedEvents = [];

            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
                $this->projectedEvents[] = $event;
            }
        };

        $ecotone = $this->bootstrapEcotone(
            [$projection::class],
            [$projection],
        );

        // When events are created in the event store
        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'John', 'bug'));
        $ecotone->sendCommand(new RegisterTicket('ticket-2', 'Jane', 'feature'));

        // Then initially no events are projected (polling hasn't run yet)
        $this->assertCount(0, $projection->projectedEvents);

        // When polling consumer runs
        $ecotone->run('polling_test_runner', ExecutionPollingMetadata::createWithTestingSetup());

        // Then all events are projected
        $this->assertCount(2, $projection->projectedEvents);
        $this->assertEquals('ticket-1', $projection->projectedEvents[0]->getTicketId());
        $this->assertEquals('ticket-2', $projection->projectedEvents[1]->getTicketId());
    }

    public function test_polling_projection_processes_events_incrementally(): void
    {
        // Given a polling projection
        $projection = new #[Projection('incremental_test'), Polling('incremental_runner'), FromStream(Ticket::class)] class {
            public array $projectedEvents = [];

            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
                $this->projectedEvents[] = $event;
            }
        };

        $ecotone = $this->bootstrapEcotone(
            [$projection::class],
            [$projection],
        );

        // When first batch of events is created
        $ecotone->sendCommand(new RegisterTicket('ticket-inc-1', 'John', 'bug'));

        // And polling runs
        $ecotone->run('incremental_runner', ExecutionPollingMetadata::createWithTestingSetup());
        $this->assertCount(1, $projection->projectedEvents);

        // When more events are created
        $ecotone->sendCommand(new RegisterTicket('ticket-inc-2', 'Jane', 'feature'));

        // And polling runs again
        $ecotone->run('incremental_runner', ExecutionPollingMetadata::createWithTestingSetup());

        // Then only new events are processed (total is 2, not 3)
        $this->assertCount(2, $projection->projectedEvents);
    }

    public function test_polling_attribute_throws_exception_when_combined_with_asynchronous(): void
    {
        // Given a projection with both Polling and Asynchronous attributes
        $projection = new #[Projection('async_polling'), Polling('async_polling_runner'), Asynchronous('async'), FromStream(Ticket::class)] class {
            public array $projectedEvents = [];

            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
                $this->projectedEvents[] = $event;
            }
        };

        // Then bootstrapping should throw ConfigurationException
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Projection 'async_polling' cannot use both #[Polling] and #[Asynchronous] attributes");

        $this->bootstrapEcotone(
            [$projection::class],
            [$projection],
            [
                SimpleMessageChannelBuilder::createQueueChannel('async'),
                PollingMetadata::create('async')->withTestingSetup(),
            ]
        );
    }

    public function test_polling_attribute_throws_exception_when_used_with_partitioned_projection(): void
    {
        // Given a projection with both Polling and partitionHeaderName
        $projection = new #[Projection('partitioned_polling', partitionHeaderName: 'aggregate.id'), Polling('invalid_runner'), FromStream(Ticket::class, aggregateType: Ticket::class)] class {
            #[EventHandler]
            public function when(TicketWasRegistered $event): void
            {
            }
        };

        // Then bootstrapping should throw ConfigurationException
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Projection 'partitioned_polling' cannot use #[Polling] attribute with partitioned projections");

        $this->bootstrapEcotone(
            [$projection::class],
            [$projection],
        );
    }

    private function bootstrapEcotone(array $classesToResolve, array $services, array $extensionObjects = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classesToResolve, [Ticket::class, TicketEventConverter::class]),
            containerOrAvailableServices: array_merge($services, [new TicketEventConverter(), self::getConnectionFactory()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ]))
                ->withExtensionObjects($extensionObjects),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
