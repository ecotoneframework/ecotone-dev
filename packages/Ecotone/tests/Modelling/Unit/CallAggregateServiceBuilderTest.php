<?php

namespace Test\Ecotone\Modelling\Unit;

use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Handler\ClassDefinition;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\AggregateMessage;
use Ecotone\Modelling\CallAggregateServiceBuilder;
use Ecotone\Modelling\InMemoryEventSourcedRepository;
use Ecotone\Test\ComponentTestBuilder;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate\AggregateWithoutMessageClassesExample;
use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\CreateOrderCommand;
use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\GetOrderAmountQuery;
use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\InMemoryStandardRepository;
use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\Order;
use Test\Ecotone\Modelling\Fixture\QueryHandlerAggregate\CreateStorage;
use Test\Ecotone\Modelling\Fixture\QueryHandlerAggregate\SmallBox;
use Test\Ecotone\Modelling\Fixture\QueryHandlerAggregate\Storage;
use Test\Ecotone\Modelling\Fixture\Ticket\AssignWorkerCommand;
use Test\Ecotone\Modelling\Fixture\Ticket\StartTicketCommand;
use Test\Ecotone\Modelling\Fixture\Ticket\Ticket;
use Test\Ecotone\Modelling\Fixture\Ticket\TicketWasStartedEvent;
use Test\Ecotone\Modelling\Fixture\Ticket\WorkerWasAssignedEvent;

/**
 * @internal
 */
class CallAggregateServiceBuilderTest extends TestCase
{
    public function test_calling_existing_aggregate_method_with_command_class()
    {
        $aggregate                      = AggregateWithoutMessageClassesExample::create(['id' => 1]);
        $aggregateCallingCommandHandler = CallAggregateServiceBuilder::create(
            ClassDefinition::createFor(TypeDescriptor::create(AggregateWithoutMessageClassesExample::class)),
            'doSomething',
            true,
            InterfaceToCallRegistry::createEmpty()
        );

        $aggregateCommandHandler = ComponentTestBuilder::create()
            ->withReference('repository', InMemoryStandardRepository::createEmpty())
            ->build($aggregateCallingCommandHandler);

        $aggregateCommandHandler->handle(
            MessageBuilder::withPayload(['id' => 1])
                ->setHeader(AggregateMessage::AGGREGATE_OBJECT, $aggregate)
                ->build()
        );

        $this->assertTrue($aggregate->getChangedState());
    }

    public function test_calling_aggregate_for_query_handler_with_return_value()
    {
        $orderAmount = 5;
        $order = Order::createWith(CreateOrderCommand::createWith(1, $orderAmount, 'Poland'));
        $order->increaseAggregateVersion();

        $aggregateCallingCommandHandler = CallAggregateServiceBuilder::create(
            ClassDefinition::createFor(TypeDescriptor::create(Order::class)),
            'getAmountWithQuery',
            true,
            InterfaceToCallRegistry::createEmpty()
        );

        $aggregateQueryHandler = ComponentTestBuilder::create()
            ->withReference('orderRepository', InMemoryStandardRepository::createEmpty())
            ->build($aggregateCallingCommandHandler);

        $replyChannel = QueueChannel::create();
        $aggregateQueryHandler->handle(
            MessageBuilder::withPayload(GetOrderAmountQuery::createWith(1))
                ->setHeader(AggregateMessage::AGGREGATE_OBJECT, $order)
                ->setReplyChannel($replyChannel)
                ->build()
        );

        $this->assertEquals(
            $orderAmount,
            $replyChannel->receive()->getPayload()
        );
    }

    public function test_providing_correct_result_type_for_reply_message_when_result_is_collection()
    {
        $aggregateId = 1;
        $aggregate = Storage::create(new CreateStorage($aggregateId, [SmallBox::create(1)], []));

        $aggregateCallingCommandHandler = CallAggregateServiceBuilder::create(
            ClassDefinition::createFor(TypeDescriptor::create(Storage::class)),
            'getSmallBoxes',
            false,
            InterfaceToCallRegistry::createEmpty()
        );

        $aggregateQueryHandler = ComponentTestBuilder::create()
            ->withReference(InMemoryStandardRepository::class, InMemoryStandardRepository::createEmpty())
            ->build($aggregateCallingCommandHandler);

        $replyChannel = QueueChannel::create();
        $aggregateQueryHandler->handle(
            MessageBuilder::withPayload(['storageId' => $aggregateId])
                ->setHeader(AggregateMessage::AGGREGATE_OBJECT, $aggregate)
                ->setReplyChannel($replyChannel)
                ->build()
        );

        $this->assertEquals(
            TypeDescriptor::createCollection(SmallBox::class),
            $replyChannel->receive()->getHeaders()->getContentType()->getTypeParameter()
        );
    }

    public function test_providing_correct_result_type_for_reply_message_when_result_is_collection_of_unions()
    {
        $aggregateId = 1;
        $aggregate = Storage::create(new CreateStorage($aggregateId, [SmallBox::create(1)], []));

        $aggregateCallingCommandHandler = CallAggregateServiceBuilder::create(
            ClassDefinition::createFor(TypeDescriptor::create(Storage::class)),
            'getBigBoxes',
            false,
            InterfaceToCallRegistry::createEmpty()
        );

        $aggregateQueryHandler = ComponentTestBuilder::create()
            ->withReference(InMemoryStandardRepository::class, InMemoryStandardRepository::createEmpty())
            ->build($aggregateCallingCommandHandler);

        $replyChannel = QueueChannel::create();
        $aggregateQueryHandler->handle(
            MessageBuilder::withPayload(['storageId' => $aggregateId])
                ->setHeader(AggregateMessage::AGGREGATE_OBJECT, $aggregate)
                ->setReplyChannel($replyChannel)
                ->build()
        );

        $this->assertEquals(
            TypeDescriptor::createCollection(TypeDescriptor::OBJECT),
            $replyChannel->receive()->getHeaders()->getContentType()->getTypeParameter()
        );
    }

    public function test_calling_aggregate_query_handler_returning_null_value()
    {
        $orderAmount = 5;
        $order = Order::createWith(CreateOrderCommand::createWith(1, $orderAmount, 'Poland'));
        $order->increaseAggregateVersion();

        $aggregateCallingCommandHandler = CallAggregateServiceBuilder::create(
            ClassDefinition::createFor(TypeDescriptor::create(Order::class)),
            'getCustomerId',
            false,
            InterfaceToCallRegistry::createEmpty()
        );

        $aggregateQueryHandler = ComponentTestBuilder::create()
            ->withReference('orderRepository', InMemoryStandardRepository::createEmpty())
            ->build($aggregateCallingCommandHandler);

        $replyChannel = QueueChannel::create();
        $aggregateQueryHandler->handle(
            MessageBuilder::withPayload(['orderId' => 1])
                ->setHeader(AggregateMessage::AGGREGATE_OBJECT, $order)
                ->setReplyChannel($replyChannel)
                ->build()
        );

        $this->assertNull($replyChannel->receive());
    }

    public function test_calling_aggregate_for_query_handler_with_no_query()
    {
        $aggregate = AggregateWithoutMessageClassesExample::create(['id' => 1]);
        $aggregateCallingCommandHandler = CallAggregateServiceBuilder::create(
            ClassDefinition::createFor(TypeDescriptor::create(AggregateWithoutMessageClassesExample::class)),
            'querySomething',
            false,
            InterfaceToCallRegistry::createEmpty()
        );

        $aggregateQueryHandler = ComponentTestBuilder::create()
            ->withReference('repository', InMemoryStandardRepository::createWith([$aggregate]))
            ->build($aggregateCallingCommandHandler);

        $replyChannel = QueueChannel::create();
        $aggregateQueryHandler->handle(
            MessageBuilder::withPayload(['id' => 1])
                ->setHeader(AggregateMessage::AGGREGATE_OBJECT, $aggregate)
                ->setReplyChannel($replyChannel)
                ->build()
        );

        $this->assertEquals(
            true,
            $replyChannel->receive()->getPayload()
        );
    }

    public function test_calling_factory_method_for_event_sourced_aggregate()
    {
        $commandToRun = new StartTicketCommand(1);

        $aggregateCallingCommandHandler = CallAggregateServiceBuilder::create(
            ClassDefinition::createFor(TypeDescriptor::create(Ticket::class)),
            'start',
            true,
            InterfaceToCallRegistry::createEmpty()
        )
            ->withInputChannelName('inputChannel');

        $replyChannel = QueueChannel::create();
        $inMemoryEventSourcedRepository = InMemoryEventSourcedRepository::createEmpty();

        $aggregateCommandHandler = ComponentTestBuilder::create()
            ->withReference('repository', $inMemoryEventSourcedRepository)
            ->build($aggregateCallingCommandHandler);

        $aggregateCommandHandler->handle(MessageBuilder::withPayload($commandToRun)->setReplyChannel($replyChannel)->build());

        $this->assertEquals(
            [new TicketWasStartedEvent(1)],
            $replyChannel->receive()->getPayload()
        );
    }

    public function test_calling_action_method_for_existing_event_sourced_aggregate()
    {
        $ticketId = 1;
        $commandToRun = new AssignWorkerCommand($ticketId, 100);

        $aggregateCallingCommandHandler = CallAggregateServiceBuilder::create(
            ClassDefinition::createFor(TypeDescriptor::create(Ticket::class)),
            'assignWorker',
            true,
            InterfaceToCallRegistry::createEmpty()
        )
            ->withInputChannelName('inputChannel');

        $queueChannel = QueueChannel::create();
        $inMemoryEventSourcedRepository = InMemoryEventSourcedRepository::createWithExistingAggregate(['ticketId' => $ticketId], Ticket::class, [new TicketWasStartedEvent($ticketId)]);

        $aggregateCommandHandler = ComponentTestBuilder::create()
            ->withReference('repository', $inMemoryEventSourcedRepository)
            ->build($aggregateCallingCommandHandler);

        $ticket = new Ticket();
        $ticket->onTicketWasStarted(new TicketWasStartedEvent($ticketId));

        $aggregateCommandHandler->handle(
            MessageBuilder::withPayload($commandToRun)
                ->setHeader(AggregateMessage::AGGREGATE_OBJECT, clone $ticket)
                ->setReplyChannel($queueChannel)->build()
        );

        $workerWasAssignedEvent = new WorkerWasAssignedEvent($ticketId, 100);
        $replyMessage = $queueChannel->receive();
        $this->assertEquals([$workerWasAssignedEvent], $replyMessage->getPayload());

        $ticket->onWorkerWasAssigned($workerWasAssignedEvent);
        $this->assertEquals($ticket, $replyMessage->getHeaders()->get(AggregateMessage::AGGREGATE_OBJECT));
    }
}
