<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\LinkingEventsWithoutProjection\EventEmitter;
use Test\Ecotone\EventSourcing\Fixture\LinkingEventsWithoutProjection\NotificationService;
use Test\Ecotone\EventSourcing\Fixture\LinkingEventsWithoutProjection\TicketListUpdatedConverter;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class LinkingEventsWithoutProjectionTest extends EventSourcingMessagingTestCase
{
    public function test_linking_events(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $this->assertState(ecotone: $ecotone, ticketId: '123', notificationsCount: 1);

        $ecotone->sendCommand(new RegisterTicket('124', 'Johnny', 'info'));
        $this->assertState(ecotone: $ecotone, ticketId: '124', notificationsCount: 2);

        $ecotone->sendCommand(new CloseTicket('123'));
        $this->assertState(ecotone: $ecotone, ticketId: '123', notificationsCount: 3);
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [
                new EventEmitter(),
                new NotificationService(),
                new TicketListUpdatedConverter(),
                new TicketEventConverter(),
                DbalConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\LinkingEventsWithoutProjection',
                ])
                ->withExtensionObjects([EventSourcingConfiguration::createWithDefaults()]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );
    }

    private function assertState(FlowTestSupport $ecotone, string $ticketId, int $notificationsCount): void
    {
        self::assertEquals($ticketId, $ecotone->sendQueryWithRouting('get.notifications'));
        self::assertCount($notificationsCount, $ecotone->sendQueryWithRouting('get.published_events'));
    }
}
