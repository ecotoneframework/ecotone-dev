<?php
/*
 * licence Enterprise
 */
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
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Basket\Basket;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\CreateBasket;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\AddProduct;
use Test\Ecotone\EventSourcing\Fixture\Basket\BasketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\Snapshots\BasketMediaTypeConverter;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Projecting\ProjectingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class MultiStreamSynchronousProjectionTest extends ProjectingTestCase
{
    public function test_building_multi_stream_synchronous_projection(): void
    {
        $projection = $this->createMultiStreamProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        self::assertEquals([], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        // write two events into Ticket stream
        $ecotone->sendCommand(new RegisterTicket('101', 'Alice', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('102', 'Bob', 'info'));

        // also write a close to verify handler works
        $ecotone->sendCommand(new CloseTicket('101'));

        $tickets = $ecotone->sendQueryWithRouting('getInProgressTickets');
        self::assertEquals([
            ['ticket_id' => '102', 'ticket_type' => 'info'],
        ], $tickets);
    }

    public function test_reset_and_delete_on_multi_stream_projection(): void
    {
        $projection = $this->createMultiStreamProjection();
        $ecotone = $this->bootstrapEcotone([$projection::class, Basket::class], [$projection]);

        // init
        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        // seed some events across both streams
        $ecotone->sendCommand(new RegisterTicket('301', 'C', 'alert'));
        $ecotone->sendCommand(new CreateBasket('b-2'));
        $ecotone->sendCommand(new AddProduct('b-2', 'Pen'));
        $ecotone->sendCommand(new RegisterTicket('302', 'D', 'warning'));

        // verify current state
        self::assertEquals([
            ['ticket_id' => '301', 'ticket_type' => 'alert'],
            ['ticket_id' => '302', 'ticket_type' => 'warning'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        // reset and trigger catch up
        $ecotone->resetProjection($projection::NAME)
            ->triggerProjection($projection::NAME);

        // after reset and catch-up, state should be re-built
        self::assertEquals([
            ['ticket_id' => '301', 'ticket_type' => 'alert'],
            ['ticket_id' => '302', 'ticket_type' => 'warning'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        // delete projection table
        $ecotone->deleteProjection($projection::NAME);

        // table should be gone
        self::assertFalse(self::tableExists($this->getConnection(), 'in_progress_tickets_multi'));
    }

    public function test_interleaving_two_streams(): void
    {
        $projection = $this->createMultiStreamProjection();
        $ecotone = $this->bootstrapEcotone([$projection::class, Basket::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        // Interleave events across two streams: Ticket and Basket
        // 1) Ticket: register ticket 201
        $ecotone->sendCommand(new RegisterTicket('201', 'A', 'alert'));
        // 2) Basket: create basket and add product
        $ecotone->sendCommand(new CreateBasket('b-1'));
        $ecotone->sendCommand(new AddProduct('b-1', 'Book'));
        // 3) Ticket: register ticket 202 and then close 201
        $ecotone->sendCommand(new RegisterTicket('202', 'B', 'info'));
        $ecotone->sendCommand(new CloseTicket('201'));

        // We only project Ticket events; ensure result reflects Ticket stream while Basket events coexist in another stream
        self::assertEquals([
            ['ticket_id' => '202', 'ticket_type' => 'info'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_exhaustive_interleaving_and_mutations_across_streams(): void
    {
        $projection = $this->createMultiStreamProjection();
        $ecotone = $this->bootstrapEcotone([$projection::class, Basket::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        // Interleave multiple operations across streams
        $ecotone->sendCommand(new RegisterTicket('401', 'Z', 'alert'));      // T1 add
        $ecotone->sendCommand(new CreateBasket('b-3'));                      // B create
        $ecotone->sendCommand(new RegisterTicket('402', 'Y', 'info'));       // T2 add
        $ecotone->sendCommand(new AddProduct('b-3', 'Notebook'));            // B add
        $ecotone->sendCommand(new CloseTicket('401'));                       // T1 close
        $ecotone->sendCommand(new RegisterTicket('403', 'X', 'warning'));    // T3 add

        // Expect tickets 402 and 403 in ascending order by id (projection sorts by id ASC on read)
        self::assertEquals([
            ['ticket_id' => '402', 'ticket_type' => 'info'],
            ['ticket_id' => '403', 'ticket_type' => 'warning'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        // Reset and rebuild should yield the same
        $ecotone->resetProjection($projection::NAME)
            ->triggerProjection($projection::NAME);
        self::assertEquals([
            ['ticket_id' => '402', 'ticket_type' => 'info'],
            ['ticket_id' => '403', 'ticket_type' => 'warning'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    private function createMultiStreamProjection(): object
    {
        $connection = $this->getConnection();

        // Configure FromStream with multiple streams: Ticket and Basket
        return new #[ProjectionV2(self::NAME), FromStream([Ticket::class, Basket::class])] class ($connection) {
            public const NAME = 'in_progress_ticket_list_multi_stream';

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getInProgressTickets')]
            public function getTickets(): array
            {
                return $this->connection->executeQuery(<<<SQL
                        SELECT * FROM in_progress_tickets_multi ORDER BY ticket_id ASC
                    SQL)->fetchAllAssociative();
            }

            #[EventHandler]
            public function addTicket(TicketWasRegistered $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO in_progress_tickets_multi VALUES (?,?)
                    SQL, [$event->getTicketId(), $event->getTicketType()]);
            }

            #[EventHandler]
            public function closeTicket(TicketWasClosed $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        DELETE FROM in_progress_tickets_multi WHERE ticket_id = ?
                    SQL, [$event->getTicketId()]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement(<<<SQL
                        CREATE TABLE IF NOT EXISTS in_progress_tickets_multi (
                            ticket_id VARCHAR(36) PRIMARY KEY,
                            ticket_type VARCHAR(25)
                        )
                    SQL);
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DROP TABLE IF EXISTS in_progress_tickets_multi
                    SQL);
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DELETE FROM in_progress_tickets_multi
                    SQL);
            }
        };
    }

    private function bootstrapEcotone(array $classesToResolve, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classesToResolve, [Ticket::class, TicketEventConverter::class, Basket::class, BasketEventConverter::class, BasketMediaTypeConverter::class]),
            containerOrAvailableServices: array_merge($services, [new TicketEventConverter(), new BasketEventConverter(), new BasketMediaTypeConverter(), self::getConnectionFactory()]),
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
