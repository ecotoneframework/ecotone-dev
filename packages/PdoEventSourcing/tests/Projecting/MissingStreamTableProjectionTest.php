<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Partitioned;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\EventSourcing\Database\EventStreamTableManager;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * @internal
 */
final class MissingStreamTableProjectionTest extends ProjectingTestCase
{
    private const STREAM = StreamTableRegistry::DEFAULT_STREAM;

    public function test_a_global_projection_names_the_setup_command_when_the_event_stream_table_is_missing(): void
    {
        $projection = $this->globalTicketProjection();
        $ecotone = $this->bootstrapWithoutTheEventStreamTable($projection);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(MissingTableInstructions::build(EventStreamTableManager::FEATURE_NAME, self::STREAM, null));

        $ecotone->triggerProjection('missing_table_global_tickets');
    }

    public function test_a_global_projection_runs_once_the_database_is_initialized(): void
    {
        $projection = $this->globalTicketProjection();
        $ecotone = $this->bootstrapWithoutTheEventStreamTable($projection);

        $ecotone->initializeDatabase();

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotone->triggerProjection('missing_table_global_tickets');

        self::assertSame(['123'], $projection->registeredTicketIds);
    }

    public function test_a_partitioned_projection_names_the_setup_command_when_the_event_stream_table_is_missing(): void
    {
        $projection = $this->partitionedTicketProjection();
        $ecotone = $this->bootstrapWithoutTheEventStreamTable($projection);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(MissingTableInstructions::build(EventStreamTableManager::FEATURE_NAME, self::STREAM, null));

        $ecotone->triggerProjection('missing_table_partitioned_tickets');
    }

    public function test_a_partitioned_projection_runs_once_the_database_is_initialized(): void
    {
        $projection = $this->partitionedTicketProjection();
        $ecotone = $this->bootstrapWithoutTheEventStreamTable($projection);

        $ecotone->initializeDatabase();

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotone->triggerProjection('missing_table_partitioned_tickets');

        self::assertSame(['123'], $projection->registeredTicketIds);
    }

    private function bootstrapWithoutTheEventStreamTable(object $projection): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [$projection::class, Ticket::class, TicketEventConverter::class],
            containerOrAvailableServices: [$projection, new TicketEventConverter(), self::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(false),
                ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->getServiceFromContainer(DatabaseSetupManager::class)->drop(EventStreamTableManager::FEATURE_NAME);

        return $ecotone;
    }

    private function globalTicketProjection(): object
    {
        return new #[Projection('missing_table_global_tickets'), FromAggregateStream(Ticket::class)] class {
            /** @var string[] */
            public array $registeredTicketIds = [];

            #[EventHandler]
            public function onTicketRegistered(TicketWasRegistered $event): void
            {
                $this->registeredTicketIds[] = $event->getTicketId();
            }
        };
    }

    private function partitionedTicketProjection(): object
    {
        return new #[Projection('missing_table_partitioned_tickets'), Partitioned, FromStream(stream: StreamTableRegistry::DEFAULT_STREAM, aggregateType: Ticket::class)] class {
            /** @var string[] */
            public array $registeredTicketIds = [];

            #[EventHandler]
            public function onTicketRegistered(TicketWasRegistered $event): void
            {
                $this->registeredTicketIds[] = $event->getTicketId();
            }
        };
    }
}
