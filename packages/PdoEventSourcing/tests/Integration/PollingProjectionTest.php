<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Basket\BasketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\AddProduct;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\CreateBasket;
use Test\Ecotone\EventSourcing\Fixture\BasketListProjection\BasketList;
use Test\Ecotone\EventSourcing\Fixture\BasketListProjection\BasketListConfiguration;
use Test\Ecotone\EventSourcing\Fixture\ProductsProjection\Products;
use Test\Ecotone\EventSourcing\Fixture\ProductsProjection\ProductsConfiguration;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection\InProgressTicketList;
use Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection\ProjectionConfiguration;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class PollingProjectionTest extends EventSourcingMessagingTestCase
{
    public function test_building_polling_projection(): void
    {
        $connectionFactory = $this->getConnectionFactory();
        $connection = $connectionFactory->createContext()
            ->getDbalConnection()
        ;

        $ecotoneLite = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [ProjectionConfiguration::class, InProgressTicketList::class],
            containerOrAvailableServices: [new InProgressTicketList($connection), new TicketEventConverter(), DbalConnectionFactory::class => $connectionFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotoneLite->initializeProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        $ecotoneLite->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotoneLite->sendCommand(new RegisterTicket('124', 'Johnny', 'alert'));
        $ecotoneLite->sendCommand(new RegisterTicket('125', 'Johnny', 'alert'));
        $ecotoneLite->sendCommand(new RegisterTicket('126', 'Johnny', 'alert'));
        $ecotoneLite->sendCommand(new RegisterTicket('127', 'Johnny', 'alert'));

        self::assertEquals([], $ecotoneLite->sendQueryWithRouting('getInProgressTickets'));

        $ecotoneLite->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '125', 'ticket_type' => 'alert'],
            ['ticket_id' => '126', 'ticket_type' => 'alert'],
            ['ticket_id' => '127', 'ticket_type' => 'alert'],
        ], $ecotoneLite->sendQueryWithRouting('getInProgressTickets'));

        $ecotoneLite->sendCommand(new CloseTicket('123'));

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '125', 'ticket_type' => 'alert'],
            ['ticket_id' => '126', 'ticket_type' => 'alert'],
            ['ticket_id' => '127', 'ticket_type' => 'alert'],
        ], $ecotoneLite->sendQueryWithRouting('getInProgressTickets'));

        $ecotoneLite->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '125', 'ticket_type' => 'alert'],
            ['ticket_id' => '126', 'ticket_type' => 'alert'],
            ['ticket_id' => '127', 'ticket_type' => 'alert'],
        ], $ecotoneLite->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_operations_on_polling_projection(): void
    {
        $connectionFactory = $this->getConnectionFactory();
        $connection = $connectionFactory->createContext()
            ->getDbalConnection()
        ;

        $ecotoneLite = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [ProjectionConfiguration::class, InProgressTicketList::class],
            containerOrAvailableServices: [new InProgressTicketList($connection), new TicketEventConverter(), DbalConnectionFactory::class => $connectionFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $ecotoneLite->initializeProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotoneLite->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotoneLite->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotoneLite->stopProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotoneLite->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotoneLite->sendCommand(new RegisterTicket('124', 'Johnny', 'alert'));

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
        ], $ecotoneLite->sendQueryWithRouting('getInProgressTickets'));

        $ecotoneLite->resetProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotoneLite->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
        ], $ecotoneLite->sendQueryWithRouting('getInProgressTickets'));

        $ecotoneLite->deleteProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotoneLite->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertFalse(self::getSchemaManager($connection)->tablesExist('in_progress_tickets'));
    }

    public function test_building_multiple_polling_projection(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [BasketListConfiguration::class, BasketList::class, ProductsConfiguration::class, Products::class],
            containerOrAvailableServices: [new BasketList(), new Products(), new BasketEventConverter(), DbalConnectionFactory::class => EventSourcingMessagingTestCase::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Basket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $ecotoneLite->sendCommand(new CreateBasket('1000'));
        $ecotoneLite->run(BasketList::PROJECTION_NAME, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 1000));

        self::assertEquals(['1000' => []], $ecotoneLite->sendQueryWithRouting('getALlBaskets'));
        self::assertEquals([], $ecotoneLite->sendQueryWithRouting('getALlProducts'));

        $ecotoneLite->sendCommand(new AddProduct('1000', 'milk'));

        $ecotoneLite->run(BasketList::PROJECTION_NAME, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 1000));
        $ecotoneLite->run(Products::PROJECTION_NAME, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 1000));

        self::assertEquals(['1000' => ['milk']], $ecotoneLite->sendQueryWithRouting('getALlBaskets'));
        self::assertEquals(['milk' => 1], $ecotoneLite->sendQueryWithRouting('getALlProducts'));
    }
}
