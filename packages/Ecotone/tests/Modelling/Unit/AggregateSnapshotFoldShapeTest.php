<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Unit;

use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\EventSourcing\EventSourcedRepositoryAdapter;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Store\Document\InMemoryDocumentStore;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\SaveAggregateService;
use Ecotone\Modelling\BaseEventSourcingConfiguration;
use Ecotone\Test\StubLogger;

use function json_encode;

use PHPUnit\Framework\TestCase;
use Test\Ecotone\Modelling\Fixture\Ticket\AssignWorkerCommand;
use Test\Ecotone\Modelling\Fixture\Ticket\StartTicketCommand;
use Test\Ecotone\Modelling\Fixture\Ticket\Ticket;

/**
 * licence Apache-2.0
 * @internal
 */
final class AggregateSnapshotFoldShapeTest extends TestCase
{
    private InMemoryDocumentStore $documentStore;

    private StubLogger $logger;

    protected function setUp(): void
    {
        $this->documentStore = InMemoryDocumentStore::createEmpty();
        $this->logger = StubLogger::create();
    }

    public function test_a_snapshot_written_before_ecotone_recorded_the_fold_shape_is_ignored(): void
    {
        $ecotone = $this->bootstrapWithSnapshots();
        $ecotone->sendCommand(new StartTicketCommand('1'));
        $this->storeSnapshotWithoutFoldShape($this->ticketAssignedTo($ecotone, 'someone-who-never-was'));

        $ecotone->sendCommand(new AssignWorkerCommand('1', 'johny'));

        $this->assertSame('johny', $ecotone->getAggregate(Ticket::class, ['ticketId' => '1'])->getWorkerId());
        $this->assertCount(1, $this->logger->getError());
    }

    public function test_a_snapshot_without_a_fold_shape_is_replaced_by_the_next_write(): void
    {
        $ecotone = $this->bootstrapWithSnapshots();
        $ecotone->sendCommand(new StartTicketCommand('1'));
        $this->storeSnapshotWithoutFoldShape($this->ticketAssignedTo($ecotone, 'someone-who-never-was'));

        $ecotone->sendCommand(new AssignWorkerCommand('1', 'johny'));
        $this->logger->clear();

        $this->assertSame('johny', $ecotone->getAggregate(Ticket::class, ['ticketId' => '1'])->getWorkerId());
        $this->assertCount(0, $this->logger->getError());
    }

    public function test_a_snapshot_taken_with_another_fold_shape_is_ignored(): void
    {
        $ecotone = $this->bootstrapWithSnapshots();
        $ecotone->sendCommand(new StartTicketCommand('1'));
        $this->documentStore->upsertDocument(
            SaveAggregateService::getSnapshotCollectionName(Ticket::class),
            SaveAggregateService::getSnapshotDocumentId(['ticketId' => '1']),
            $this->ticketAssignedTo($ecotone, 'someone-who-never-was'),
        );
        $this->documentStore->upsertDocument(
            EventSourcedRepositoryAdapter::getSnapshotFoldShapeCollectionName(Ticket::class),
            SaveAggregateService::getSnapshotDocumentId(['ticketId' => '1']),
            json_encode(['foldShape' => 'the aggregate folded other events back then']),
        );

        $ecotone->sendCommand(new AssignWorkerCommand('1', 'johny'));

        $this->assertSame('johny', $ecotone->getAggregate(Ticket::class, ['ticketId' => '1'])->getWorkerId());
        $this->assertCount(1, $this->logger->getError());
    }

    private function ticketAssignedTo(FlowTestSupport $ecotone, string $workerId): Ticket
    {
        $ticket = $ecotone->getAggregate(Ticket::class, ['ticketId' => '1']);
        $ticket->onWorkerWasAssigned(new \Test\Ecotone\Modelling\Fixture\Ticket\WorkerWasAssignedEvent('1', $workerId));

        return $ticket;
    }

    private function storeSnapshotWithoutFoldShape(Ticket $ticket): void
    {
        $this->documentStore->upsertDocument(
            SaveAggregateService::getSnapshotCollectionName(Ticket::class),
            SaveAggregateService::getSnapshotDocumentId(['ticketId' => '1']),
            $ticket,
        );
        $this->documentStore->dropCollection(EventSourcedRepositoryAdapter::getSnapshotFoldShapeCollectionName(Ticket::class));
    }

    private function bootstrapWithSnapshots(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [Ticket::class],
            [DocumentStore::class => $this->documentStore, 'logger' => $this->logger],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    InMemoryRepositoryBuilder::createDefaultEventSourcedRepository(),
                    BaseEventSourcingConfiguration::withDefaults()->withSnapshotsFor(Ticket::class, 1),
                ])
        );
    }
}
