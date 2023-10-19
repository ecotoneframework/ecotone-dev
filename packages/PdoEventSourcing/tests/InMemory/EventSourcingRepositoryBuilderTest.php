<?php

namespace Test\Ecotone\EventSourcing\InMemory;

use Ecotone\EventSourcing\AggregateStreamMapping;
use Ecotone\EventSourcing\AggregateTypeMapping;
use Ecotone\EventSourcing\EventMapper;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\EventSourcingRepositoryBuilder;
use Ecotone\EventSourcing\Prooph\LazyProophEventStore;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\InMemoryConversionService;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Store\Document\DocumentStore;
use Ecotone\Messaging\Store\Document\InMemoryDocumentStore;
use Ecotone\Modelling\SaveAggregateService;
use Ecotone\Modelling\SnapshotEvent;
use Ecotone\Test\ComponentTestBuilder;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\AssignedPersonWasChanged;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;

/**
 * @internal
 */
class EventSourcingRepositoryBuilderTest extends EventSourcingMessagingTestCase
{
    public function test_storing_and_retrieving()
    {
        $configuration = EventSourcingConfiguration::createWithDefaults()
            ->withSingleStreamPersistenceStrategy();
        $proophRepositoryBuilder = EventSourcingRepositoryBuilder::create($configuration);

        $ticketId = Uuid::uuid4()->toString();
        $ticketWasRegisteredEvent = new TicketWasRegistered($ticketId, 'Johny', 'standard');
        $ticketWasRegisteredEventAsArray = [
            'ticketId' => $ticketId,
            'assignedPerson' => 'Johny',
            'ticketType' => 'standard',
        ];

        $repository = self::componentTesting($configuration)
            ->withReference(
                ConversionService::REFERENCE_NAME,
                InMemoryConversionService::createWithoutConversion()
                    ->registerInPHPConversion($ticketWasRegisteredEvent, $ticketWasRegisteredEventAsArray)
                    ->registerInPHPConversion($ticketWasRegisteredEventAsArray, $ticketWasRegisteredEvent)
            )
            ->build($proophRepositoryBuilder);

        $repository->save(['ticketId' => $ticketId], Ticket::class, [$ticketWasRegisteredEvent], [
            MessageHeaders::TIMESTAMP => 1610285647,
        ], 0);

        $resultStream = $repository->findBy(Ticket::class, ['ticketId' => $ticketId]);
        $this->assertEquals(1, $resultStream->getAggregateVersion());
        $this->assertEquals($ticketWasRegisteredEvent, $resultStream->getEvents()[0]->getPayload());
    }

    public function test_retrieving_with_snaphots()
    {
        $configuration = EventSourcingConfiguration::createWithDefaults()
            ->withSnapshots([Ticket::class], 1);
        $proophRepositoryBuilder = EventSourcingRepositoryBuilder::create($configuration);

        $ticketId = Uuid::uuid4()->toString();
        $documentStore = InMemoryDocumentStore::createEmpty();
        $ticket = new Ticket();
        $ticketWasRegistered = new TicketWasRegistered($ticketId, 'Johny', 'standard');
        $ticket->setVersion(1);
        $ticketWasRegisteredEventAsArray = [
            'ticketId' => $ticketId,
            'ticketType' => 'standard',
        ];
        $workerWasAssigned = new AssignedPersonWasChanged($ticketId, 100);
        $workerWasAssignedAsArray = [
            'ticketId' => $ticketId,
            'assignedWorkerId' => 100,
        ];

        $ticket->applyTicketWasRegistered($ticketWasRegistered);
        $documentStore->addDocument(SaveAggregateService::getSnapshotCollectionName(Ticket::class), $ticketId, $ticket);

        $repository = self::componentTesting($configuration)
            ->withReference(
                ConversionService::REFERENCE_NAME,
                InMemoryConversionService::createWithoutConversion()
                    ->registerInPHPConversion($ticketWasRegistered, $ticketWasRegisteredEventAsArray)
                    ->registerInPHPConversion($ticketWasRegisteredEventAsArray, $ticketWasRegistered)
                    ->registerInPHPConversion($workerWasAssigned, $workerWasAssignedAsArray)
                    ->registerInPHPConversion($workerWasAssignedAsArray, $workerWasAssigned)
            )
            ->withReference(DocumentStore::class, $documentStore)
            ->build($proophRepositoryBuilder);

        $repository->save(['ticketId' => $ticketId], Ticket::class, [$ticketWasRegistered, $workerWasAssigned], [
            MessageHeaders::TIMESTAMP => 1610285647,
        ], 0);

        $resultStream = $repository->findBy(Ticket::class, ['ticketId' => $ticketId]);
        $this->assertEquals(2, $resultStream->getAggregateVersion());
        $this->assertEquals(new SnapshotEvent($ticket), $resultStream->getEvents()[0]);
        $this->assertEquals($workerWasAssigned, $resultStream->getEvents()[1]->getPayload());
    }

    public function test_retrieving_with_snaphots_not_extist_in_documentstore()
    {
        $configuration = EventSourcingConfiguration::createWithDefaults()
            ->withSnapshots([Ticket::class], 1);
        $proophRepositoryBuilder = EventSourcingRepositoryBuilder::create($configuration);

        $ticketId = Uuid::uuid4()->toString();
        $documentStore = InMemoryDocumentStore::createEmpty();
        $ticket = new Ticket();
        $ticketWasRegistered = new TicketWasRegistered($ticketId, 'Johny', 'standard');
        $ticket->setVersion(1);
        $ticketWasRegisteredEventAsArray = [
            'ticketId' => $ticketId,
            'ticketType' => 'standard',
        ];
        $workerWasAssigned = new AssignedPersonWasChanged($ticketId, 100);
        $workerWasAssignedAsArray = [
            'ticketId' => $ticketId,
            'assignedWorkerId' => 100,
        ];

        $ticket->applyTicketWasRegistered($ticketWasRegistered);

        $repository = self::componentTesting($configuration)
            ->withReference(
                ConversionService::REFERENCE_NAME,
                InMemoryConversionService::createWithoutConversion()
                    ->registerInPHPConversion($ticketWasRegistered, $ticketWasRegisteredEventAsArray)
                    ->registerInPHPConversion($ticketWasRegisteredEventAsArray, $ticketWasRegistered)
                    ->registerInPHPConversion($workerWasAssigned, $workerWasAssignedAsArray)
                    ->registerInPHPConversion($workerWasAssignedAsArray, $workerWasAssigned)
            )
            ->withReference(DocumentStore::class, $documentStore)
            ->build($proophRepositoryBuilder);

        $repository->save(['ticketId' => $ticketId], Ticket::class, [$ticketWasRegistered, $workerWasAssigned], [
            MessageHeaders::TIMESTAMP => 1610285647,
        ], 0);

        $resultStream = $repository->findBy(Ticket::class, ['ticketId' => $ticketId]);
        $this->assertEquals(2, $resultStream->getAggregateVersion());
        $this->assertEquals($workerWasAssigned, $resultStream->getEvents()[1]->getPayload());
    }

    public function test_having_two_streams_for_difference_instances_of_same_aggregate_using_aggregate_stream_strategy()
    {
        $configuration =             EventSourcingConfiguration::createWithDefaults()
            ->withStreamPerAggregatePersistenceStrategy();
        $proophRepositoryBuilder = EventSourcingRepositoryBuilder::create($configuration);

        $firstTicketAggregate = Uuid::uuid4()->toString();
        $secondTicketAggregate = Uuid::uuid4()->toString();
        $firstTicketWasRegisteredEvent = new TicketWasRegistered($firstTicketAggregate, 'Johny', 'standard');
        $firstTicketWasRegisteredEventAsArray = [
            'ticketId' => $firstTicketAggregate,
            'assignedPerson' => 'Johny',
            'ticketType' => 'standard',
        ];
        $secondTicketWasRegisteredEvent = new TicketWasRegistered($secondTicketAggregate, 'Johny', 'standard');
        $secondTicketWasRegisteredEventAsArray = [
            'ticketId' => $secondTicketAggregate,
            'assignedPerson' => 'Johny',
            'ticketType' => 'standard',
        ];

        $repository = self::componentTesting($configuration)
            ->withReference(
                ConversionService::REFERENCE_NAME,
                InMemoryConversionService::createWithoutConversion()
                    ->registerInPHPConversion($firstTicketWasRegisteredEvent, $firstTicketWasRegisteredEventAsArray)
                    ->registerInPHPConversion($firstTicketWasRegisteredEventAsArray, $firstTicketWasRegisteredEvent)
                    ->registerInPHPConversion($secondTicketWasRegisteredEvent, $secondTicketWasRegisteredEventAsArray)
                    ->registerInPHPConversion($secondTicketWasRegisteredEventAsArray, $secondTicketWasRegisteredEvent)
            )
            ->build($proophRepositoryBuilder);

        $repository->save(['ticketId' => $firstTicketAggregate], Ticket::class, [$firstTicketWasRegisteredEvent], [
            MessageHeaders::TIMESTAMP => 1610285647,
        ], 0);

        $repository->save(['ticketId' => $secondTicketAggregate], Ticket::class, [$secondTicketWasRegisteredEvent], [
            MessageHeaders::TIMESTAMP => 1610285647,
        ], 0);

        $resultStream = $repository->findBy(Ticket::class, ['ticketId' => $firstTicketAggregate]);
        $this->assertEquals(1, $resultStream->getAggregateVersion());
        $this->assertEquals($firstTicketWasRegisteredEvent, $resultStream->getEvents()[0]->getPayload());

        $resultStream = $repository->findBy(Ticket::class, ['ticketId' => $secondTicketAggregate]);
        $this->assertEquals(1, $resultStream->getAggregateVersion());
        $this->assertEquals($secondTicketWasRegisteredEvent, $resultStream->getEvents()[0]->getPayload());
    }

    public function test_having_two_streams_for_difference_instances_of_same_aggregate_using_single_stream_strategy()
    {
        $configuration = EventSourcingConfiguration::createWithDefaults()
            ->withSingleStreamPersistenceStrategy();

        $proophRepositoryBuilder = EventSourcingRepositoryBuilder::create($configuration);

        $firstTicketAggregate = Uuid::uuid4()->toString();
        $secondTicketAggregate = Uuid::uuid4()->toString();
        $firstTicketWasRegisteredEvent = new TicketWasRegistered($firstTicketAggregate, 'Johny', 'standard');
        $firstTicketWasRegisteredEventAsArray = [
            'ticketId' => $firstTicketAggregate,
            'assignedPerson' => 'Johny',
            'ticketType' => 'standard',
        ];
        $secondTicketWasRegisteredEvent = new TicketWasRegistered($secondTicketAggregate, 'Johny', 'standard');
        $secondTicketWasRegisteredEventAsArray = [
            'ticketId' => $secondTicketAggregate,
            'assignedPerson' => 'Johny',
            'ticketType' => 'standard',
        ];

        $repository = self::componentTesting($configuration)
            ->withReference(
                ConversionService::REFERENCE_NAME,
                InMemoryConversionService::createWithoutConversion()
                    ->registerInPHPConversion($firstTicketWasRegisteredEvent, $firstTicketWasRegisteredEventAsArray)
                    ->registerInPHPConversion($firstTicketWasRegisteredEventAsArray, $firstTicketWasRegisteredEvent)
                    ->registerInPHPConversion($secondTicketWasRegisteredEvent, $secondTicketWasRegisteredEventAsArray)
                    ->registerInPHPConversion($secondTicketWasRegisteredEventAsArray, $secondTicketWasRegisteredEvent)
            )
            ->build($proophRepositoryBuilder);

        $repository->save(['ticketId' => $firstTicketAggregate], Ticket::class, [$firstTicketWasRegisteredEvent], [
            MessageHeaders::TIMESTAMP => 1610285647,
        ], 0);

        $repository->save(['ticketId' => $secondTicketAggregate], Ticket::class, [$secondTicketWasRegisteredEvent], [
            MessageHeaders::TIMESTAMP => 1610285647,
        ], 0);

        $resultStream = $repository->findBy(Ticket::class, ['ticketId' => $firstTicketAggregate]);
        $this->assertEquals(1, $resultStream->getAggregateVersion());
        $this->assertEquals($firstTicketWasRegisteredEvent, $resultStream->getEvents()[0]->getPayload());

        $resultStream = $repository->findBy(Ticket::class, ['ticketId' => $secondTicketAggregate]);
        $this->assertEquals(1, $resultStream->getAggregateVersion());
        $this->assertEquals($secondTicketWasRegisteredEvent, $resultStream->getEvents()[0]->getPayload());
    }

    public function test_handling_connection_as_registry()
    {
        $configuration = EventSourcingConfiguration::createWithDefaults()
            ->withSingleStreamPersistenceStrategy();

        $proophRepositoryBuilder = EventSourcingRepositoryBuilder::create($configuration);

        $ticketId = Uuid::uuid4()->toString();
        $ticketWasRegisteredEvent = new TicketWasRegistered($ticketId, 'Johny', 'standard');
        $ticketWasRegisteredEventAsArray = [
            'ticketId' => $ticketId,
            'assignedPerson' => 'Johny',
            'ticketType' => 'standard',
        ];

        $repository = self::componentTesting($configuration)
            ->withReference(
                ConversionService::REFERENCE_NAME,
                InMemoryConversionService::createWithoutConversion()
                    ->registerInPHPConversion($ticketWasRegisteredEvent, $ticketWasRegisteredEventAsArray)
                    ->registerInPHPConversion($ticketWasRegisteredEventAsArray, $ticketWasRegisteredEvent)
            )
            ->build($proophRepositoryBuilder);

        $repository->save(['ticketId' => $ticketId], Ticket::class, [$ticketWasRegisteredEvent], [
            MessageHeaders::TIMESTAMP => 1610285647,
        ], 0);

        $resultStream = $repository->findBy(Ticket::class, ['ticketId' => $ticketId]);
        $this->assertEquals(1, $resultStream->getAggregateVersion());
        $this->assertEquals($ticketWasRegisteredEvent, $resultStream->getEvents()[0]->getPayload());
    }

    protected static function componentTesting(EventSourcingConfiguration $eventSourcingConfiguration): ComponentTestBuilder
    {
        $eventMapper = EventMapper::createEmpty();
        $eventStore = new LazyProophEventStore(
            $eventSourcingConfiguration,
            $eventMapper,
            self::getConnectionFactory(),
        );
        return ComponentTestBuilder::create()
            ->withReference(EventMapper::class, $eventMapper)
            ->withReference(EventSourcingConfiguration::class, $eventSourcingConfiguration)
            ->withReference(AggregateStreamMapping::class, AggregateStreamMapping::createEmpty())
            ->withReference(AggregateTypeMapping::class, AggregateTypeMapping::createEmpty())
            ->withReference(LazyProophEventStore::class, $eventStore);
    }
}
