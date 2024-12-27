<?php

namespace Test\Ecotone\Modelling\Unit;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\ClassDefinition;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivatorBuilder;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\NullableMessageChannel;
use Ecotone\Messaging\Store\Document\DocumentStore;
use Ecotone\Messaging\Store\Document\InMemoryDocumentStore;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\AggregateFlow\CallAggregate\CallAggregateServiceBuilder;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\SaveAggregateServiceBuilder;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\SaveAggregateService;
use Ecotone\Modelling\AggregateMessage;
use Ecotone\Modelling\BaseEventSourcingConfiguration;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\InMemoryEventSourcedRepository;
use Ecotone\Modelling\NoCorrectIdentifierDefinedException;
use Ecotone\Modelling\StandardRepository;
use Ecotone\Test\ComponentTestBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use stdClass;
use Test\Ecotone\Modelling\Fixture\AggregateServiceBuilder\AggregateCreated;
use Test\Ecotone\Modelling\Fixture\AggregateServiceBuilder\CreateAggregate;
use Test\Ecotone\Modelling\Fixture\AggregateServiceBuilder\CreateSomething;
use Test\Ecotone\Modelling\Fixture\AggregateServiceBuilder\DoSomething;
use Test\Ecotone\Modelling\Fixture\AggregateServiceBuilder\EventSourcingAggregateWithInternalRecorder;
use Test\Ecotone\Modelling\Fixture\AggregateServiceBuilder\Something;
use Test\Ecotone\Modelling\Fixture\AggregateServiceBuilder\SomethingWasCreated;
use Test\Ecotone\Modelling\Fixture\Blog\Article;
use Test\Ecotone\Modelling\Fixture\Blog\PublishArticleCommand;
use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\CreateOrderCommand;
use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\GetOrderAmountQuery;
use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\InMemoryStandardRepository;
use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\Order;
use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\OrderWithManualVersioning;
use Test\Ecotone\Modelling\Fixture\EventSourcedAggregateWithInternalEventRecorder\FinishJob;
use Test\Ecotone\Modelling\Fixture\EventSourcedAggregateWithInternalEventRecorder\Job;
use Test\Ecotone\Modelling\Fixture\EventSourcedAggregateWithInternalEventRecorder\JobRepositoryInterface;
use Test\Ecotone\Modelling\Fixture\EventSourcedAggregateWithInternalEventRecorder\JobWasFinished;
use Test\Ecotone\Modelling\Fixture\EventSourcedAggregateWithInternalEventRecorder\JobWasStarted;
use Test\Ecotone\Modelling\Fixture\EventSourcedAggregateWithInternalEventRecorder\StartJob;
use Test\Ecotone\Modelling\Fixture\IncorrectEventSourcedAggregate\NoIdDefinedAfterCallingFactory\NoIdDefinedAfterRecordingEvents;
use Test\Ecotone\Modelling\Fixture\IncorrectEventSourcedAggregate\PublicIdentifierGetMethodForEventSourcedAggregate;
use Test\Ecotone\Modelling\Fixture\IncorrectEventSourcedAggregate\PublicIdentifierGetMethodWithParameters;
use Test\Ecotone\Modelling\Fixture\Ticket\AssignWorkerCommand;
use Test\Ecotone\Modelling\Fixture\Ticket\StartTicketCommand;
use Test\Ecotone\Modelling\Fixture\Ticket\Ticket;
use Test\Ecotone\Modelling\Fixture\Ticket\TicketWasStartedEvent;

/**
 * Class ServiceCallToAggregateAdapterTest
 * @package Test\Ecotone\Modelling
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 *
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class SaveAggregateServiceBuilderTest extends TestCase
{
    public function test_saving_aggregate_method_with_only_command_as_parameter()
    {
        $this->assertEquals(
            1,
            EcotoneLite::bootstrapFlowTesting(
                [Order::class]
            )
                ->sendCommand(CreateOrderCommand::createWith(1, 10, 'Poland'))
                ->getAggregate(Order::class, ['orderId' => 1])
                ->getId()
        );
    }

    public function test_snapshoting_aggregate_after_single_event()
    {
        $inMemoryDocumentStore = InMemoryDocumentStore::createEmpty();

        $ticket = EcotoneLite::bootstrapFlowTesting(
            [Ticket::class],
            [DocumentStore::class => $inMemoryDocumentStore],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    (new BaseEventSourcingConfiguration())->withSnapshotsFor(Ticket::class, 1)
                ])
        )
            ->sendCommand(new StartTicketCommand($ticketId = 1))
            ->getAggregate(Ticket::class, ['ticketId' => $ticketId]);

        $this->assertEquals(
            $ticket,
            $inMemoryDocumentStore->getDocument(SaveAggregateService::getSnapshotCollectionName(Ticket::class), 1)
        );
    }

    public function test_skipping_snapshot_if_aggregate_not_registered_for_snapshoting()
    {
        $inMemoryDocumentStore = InMemoryDocumentStore::createEmpty();

        EcotoneLite::bootstrapFlowTesting(
            [Ticket::class],
            [DocumentStore::class => $inMemoryDocumentStore],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    (new BaseEventSourcingConfiguration())
                ])
        )
            ->sendCommand(new StartTicketCommand($ticketId = 1));

        $this->assertEquals(0, $inMemoryDocumentStore->countDocuments(SaveAggregateService::getSnapshotCollectionName(Ticket::class)));
    }

    public function test_skipping_snapshot_if_not_desired_version_yet()
    {
        $inMemoryDocumentStore = InMemoryDocumentStore::createEmpty();

        EcotoneLite::bootstrapFlowTesting(
            [Ticket::class],
            [DocumentStore::class => $inMemoryDocumentStore],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    (new BaseEventSourcingConfiguration())->withSnapshotsFor(Ticket::class, 2)
                ])
        )
            ->sendCommand(new StartTicketCommand(1));

        $this->assertEquals(0, $inMemoryDocumentStore->countDocuments(SaveAggregateService::getSnapshotCollectionName(Ticket::class)));
    }

    public function test_returning_all_identifiers_assigned_during_aggregate_creation()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [Article::class]
        );

        $this->assertEquals(
            ['author' => 'johny', 'title' => 'Cat book'],
            $ecotoneLite
                ->getGateway(CommandBus::class)
                ->send(PublishArticleCommand::createWith('johny', 'Cat book', 'Good content'))
        );
    }

    public function test_calling_save_method_with_automatic_increasing_version()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [Order::class]
        );

        $aggregate = $ecotoneLite
            ->sendCommand(CreateOrderCommand::createWith(1, 1, 'Poland'))
            ->getAggregate(Order::class, ['orderId' => 1]);

        $this->assertEquals(1, $aggregate->getVersion());
    }

    public function test_calling_save_method_with_manual_increasing_version()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [OrderWithManualVersioning::class]
        );

        $aggregate = $ecotoneLite
            ->sendCommandWithRoutingKey('order.create', CreateOrderCommand::createWith(1, 1, 'Poland'))
            ->getAggregate(OrderWithManualVersioning::class, ['orderId' => 1]);

        $this->assertEquals(0, $aggregate->getVersion());
    }

    public function test_throwing_exception_if_aggregate_before_saving_has_no_nullable_identifier()
    {
        $this->expectException(InvalidArgumentException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [NoIdDefinedAfterRecordingEvents::class]
        );
    }

    public function test_throwing_exception_if_aggregate_identifier_getter_has_parameters()
    {
        $this->expectException(NoCorrectIdentifierDefinedException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [PublicIdentifierGetMethodWithParameters::class]
        );
    }

    public function test_result_aggregate_are_published_in_order(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [Job::class]
        );

        $jobId = Uuid::uuid4()->toString();
        $newJobId = Uuid::uuid4()->toString();

        self::assertEquals(
            [
                JobWasFinished::recordWith($jobId),
                JobWasStarted::recordWith($newJobId),
            ],
            $ecotoneLite
                ->sendCommand(new StartJob($jobId))
                ->discardRecordedMessages()
                ->sendCommandWithRoutingKey('job.finish_and_start', new FinishJob($jobId), metadata: [
                    'newJobId' => $newJobId,
                ])
                ->getRecordedEvents(),
        );
    }

    public function test_calling_action_method_of_existing_event_sourcing_aggregate_with_internal_recorder_which_creates_another_aggregate(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [EventSourcingAggregateWithInternalRecorder::class, Something::class],
        )
            ->sendCommand(new CreateAggregate($id = 1))
            ->sendCommand(new CreateSomething($id, $somethingId = 200));

        $this->assertSame(
            2,
            $ecotoneLite
                ->getAggregate(EventSourcingAggregateWithInternalRecorder::class, ['id' => $id])
                ->getVersion()
        );

        $this->assertEquals(
            1,
            $ecotoneLite
                ->getAggregate(Something::class, ['id' => $somethingId])
                ->getVersion()
        );
    }
}
