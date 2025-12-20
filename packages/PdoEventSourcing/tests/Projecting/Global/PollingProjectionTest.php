<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute\Polling;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\Fixture\Basket\Basket;
use Test\Ecotone\EventSourcing\Fixture\Basket\BasketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\AddProduct;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\CreateBasket;
use Test\Ecotone\EventSourcing\Fixture\Basket\Event\BasketWasCreated;
use Test\Ecotone\EventSourcing\Fixture\Basket\Event\ProductWasAddedToBasket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Projecting\ProjectingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class PollingProjectionTest extends ProjectingTestCase
{
    public function test_building_polling_projection(): void
    {
        $projection = $this->createPollingProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('124', 'Johnny', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('125', 'Johnny', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('126', 'Johnny', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('127', 'Johnny', 'alert'));

        self::assertEquals([], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->triggerProjection($projection::NAME);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '125', 'ticket_type' => 'alert'],
            ['ticket_id' => '126', 'ticket_type' => 'alert'],
            ['ticket_id' => '127', 'ticket_type' => 'alert'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new CloseTicket('123'));

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '125', 'ticket_type' => 'alert'],
            ['ticket_id' => '126', 'ticket_type' => 'alert'],
            ['ticket_id' => '127', 'ticket_type' => 'alert'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->triggerProjection($projection::NAME);

        self::assertEquals([
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '125', 'ticket_type' => 'alert'],
            ['ticket_id' => '126', 'ticket_type' => 'alert'],
            ['ticket_id' => '127', 'ticket_type' => 'alert'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_operations_on_polling_projection(): void
    {
        $projection = $this->createPollingProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotone->triggerProjection($projection::NAME);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new RegisterTicket('124', 'Johnny', 'alert'));

        $ecotone->resetProjection($projection::NAME)
            ->triggerProjection($projection::NAME);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->deleteProjection($projection::NAME);
        self::assertFalse(self::tableExists($this->getConnection(), 'in_progress_tickets'));
    }

    public function test_building_multiple_polling_projection(): void
    {
        $basketListProjection = $this->createBasketListProjection();
        $productsProjection = $this->createProductsProjection();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [
                $basketListProjection::class,
                $productsProjection::class,
                Basket::class,
                BasketEventConverter::class,
                BasketWasCreated::class,
                ProductWasAddedToBasket::class,
            ],
            containerOrAvailableServices: [
                $basketListProjection,
                $productsProjection,
                new BasketEventConverter(),
                self::getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ])),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->deleteProjection($basketListProjection::NAME);
        $ecotone->deleteProjection($productsProjection::NAME);
        $ecotone->initializeProjection($basketListProjection::NAME);
        $ecotone->initializeProjection($productsProjection::NAME);

        $ecotone->sendCommand(new CreateBasket('1000'));
        $ecotone->triggerProjection($basketListProjection::NAME);

        self::assertEquals(['1000' => []], $ecotone->sendQueryWithRouting('getALlBaskets'));
        self::assertEquals([], $ecotone->sendQueryWithRouting('getALlProducts'));

        $ecotone->sendCommand(new AddProduct('1000', 'milk'));

        $ecotone->triggerProjection($basketListProjection::NAME);
        $ecotone->triggerProjection($productsProjection::NAME);

        self::assertEquals(['1000' => ['milk']], $ecotone->sendQueryWithRouting('getALlBaskets'));
        self::assertEquals(['milk' => 1], $ecotone->sendQueryWithRouting('getALlProducts'));
    }

    private function createPollingProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Polling(self::ENDPOINT_ID), FromStream(Ticket::class)] class ($connection) {
            public const NAME = 'polling_ticket_list';
            public const ENDPOINT_ID = 'polling_ticket_list_runner';

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getInProgressTickets')]
            public function getTickets(): array
            {
                return $this->connection->executeQuery(<<<SQL
                        SELECT * FROM in_progress_tickets ORDER BY ticket_id ASC
                    SQL)->fetchAllAssociative();
            }

            #[EventHandler]
            public function addTicket(TicketWasRegistered $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO in_progress_tickets VALUES (?,?)
                    SQL, [$event->getTicketId(), $event->getTicketType()]);
            }

            #[EventHandler]
            public function closeTicket(TicketWasClosed $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        DELETE FROM in_progress_tickets WHERE ticket_id = ?
                    SQL, [$event->getTicketId()]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement(<<<SQL
                        CREATE TABLE IF NOT EXISTS in_progress_tickets (
                            ticket_id VARCHAR(36) PRIMARY KEY,
                            ticket_type VARCHAR(25)
                        )
                    SQL);
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DROP TABLE IF EXISTS in_progress_tickets
                    SQL);
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DELETE FROM in_progress_tickets
                    SQL);
            }
        };
    }

    private function createBasketListProjection(): object
    {
        return new #[ProjectionV2('basketList'), Polling('basketList_runner'), FromStream(Basket::BASKET_STREAM)] class () {
            public const NAME = 'basketList';
            public const ENDPOINT_ID = 'basketList_runner';
            private array $basketsList = [];

            #[EventHandler(BasketWasCreated::EVENT_NAME)]
            public function addBasket(array $event): void
            {
                $this->basketsList[$event['id']] = [];
            }

            #[EventHandler(ProductWasAddedToBasket::EVENT_NAME)]
            public function addProduct(ProductWasAddedToBasket $event): void
            {
                $this->basketsList[$event->getId()][] = $event->getProductName();
            }

            #[QueryHandler('getALlBaskets')]
            public function getAllBaskets(): array
            {
                return $this->basketsList;
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->basketsList = [];
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->basketsList = [];
            }
        };
    }

    private function createProductsProjection(): object
    {
        return new #[ProjectionV2('products'), Polling('products_runner'), FromStream(Basket::BASKET_STREAM)] class () {
            public const NAME = 'products';
            public const ENDPOINT_ID = 'products_runner';
            private array $products = [];

            #[EventHandler(ProductWasAddedToBasket::EVENT_NAME)]
            public function when(ProductWasAddedToBasket $event): void
            {
                if (array_key_exists($event->getProductName(), $this->products)) {
                    ++$this->products[$event->getProductName()];
                }
                $this->products[$event->getProductName()] = 1;
            }

            #[QueryHandler('getALlProducts')]
            public function getAllProducts(): array
            {
                return $this->products;
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->products = [];
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->products = [];
            }
        };
    }

    private function bootstrapEcotone(array $classesToResolve, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classesToResolve, [Ticket::class, TicketEventConverter::class]),
            containerOrAvailableServices: array_merge($services, [new TicketEventConverter(), self::getConnectionFactory()]),
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
