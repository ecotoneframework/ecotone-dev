<?php

namespace Test\Ecotone\Lite\Test;

use DateTimeImmutable;
use Ecotone\Api\CommandBus;
use Ecotone\Api\ExecutionPollingMetadata;
use Ecotone\Api\PollingMetadata;
use Ecotone\Api\ServiceConfiguration;
use Ecotone\Api\SimpleMessageChannelBuilder;
use Ecotone\Api\TestConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;
use Ecotone\Messaging\Conversion\ConversionException;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\DestinationResolutionException;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Scheduling\DatePoint;
use Ecotone\Messaging\Scheduling\TimeSpan;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\CreateOrderCommand;
use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\GetShippingAddressQuery;
use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\Notification;
use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\Order;
use Test\Ecotone\Modelling\Fixture\Order\ChannelConfiguration;
use Test\Ecotone\Modelling\Fixture\Order\OrderService;
use Test\Ecotone\Modelling\Fixture\Order\OrderWasPlaced;
use Test\Ecotone\Modelling\Fixture\Order\OrderWasPlacedConverter;
use Test\Ecotone\Modelling\Fixture\Order\PlaceOrder;
use Test\Ecotone\Modelling\Fixture\Order\PlaceOrderConverter;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class MessagingTestSupportFrameworkTest extends TestCase
{
    public function test_bootstraping_with_given_set_of_classes()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, ChannelConfiguration::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withEnvironment('test')
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders')),
        );

        $orderId = 'someId';
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));

        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertNotEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_bootstraping_with_namespace()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [],
            [new OrderService(), new PlaceOrderConverter(), new OrderWasPlacedConverter()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withEnvironment('test')
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders'))
                ->withNamespaces(["Test\Ecotone\Modelling\Fixture\Order"]),
            pathToRootCatalog: __DIR__ . '/../../'
        );

        $orderId = 'someId';
        $ecotoneTestSupport->sendCommandWithRoutingKey('order.register', new PlaceOrder($orderId));

        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertNotEmpty($ecotoneTestSupport->sendQueryWithRouting('order.getOrders'));
    }

    public function test_bootstraping_with_given_set_of_classes_and_asynchronous_module()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, ChannelConfiguration::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
        );

        $orderId = 'someId';
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));
        $this->assertEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));

        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        $this->assertNotEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_sending_command_which_requires_serialization()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, PlaceOrderConverter::class],
            [new OrderService(), new PlaceOrderConverter()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withEnvironment('test')
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders')),
        );

        $orderId = 'someId';
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', ['orderId' => $orderId]);

        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertNotEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_sending_command_which_requires_serialization_with_converter_by_class()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, ChannelConfiguration::class, PlaceOrderConverter::class],
            [new OrderService(), new PlaceOrderConverter()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withEnvironment('test')
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders')),
        );

        $orderId = 'someId';
        $ecotoneTestSupport->getCommandBus()->sendWithRouting(PlaceOrder::class, ['orderId' => $orderId]);

        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertNotEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_failing_serializing_command_message_due_to_lack_of_converter()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('orders', conversionMediaType: MediaType::createApplicationXPHPArray()),
                    PollingMetadata::create('orders')
                        ->withTestingSetup(2),
                ]),
        );

        /** Failing on command serialization */
        $this->expectException(ConversionException::class);

        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder('someId'));
    }

    public function test_failing_serializing_event_message_due_to_lack_of_converter()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, PlaceOrderConverter::class],
            [new OrderService(), new PlaceOrderConverter()],
            testConfiguration: TestConfiguration::createWithDefaults()
                ->withSpyOnChannel('orders'),
            configuration: ServiceConfiguration::createWithDefaults()->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders', conversionMediaType: MediaType::createApplicationXPHPArray()))
        );

        $ecotoneTestSupport->sendCommandWithRoutingKey('order.register', new PlaceOrder('someId'));

        $this->assertEquals(
            [['orderId' => 'someId']],
            $ecotoneTestSupport->getRecordedMessagePayloadsFrom('orders')
        );

        /** Failing on event serialization */
        $this->expectException(ConversionException::class);

        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
    }

    public function test_serializing_command_and_event_before_sending_to_asynchronous_channel()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, PlaceOrderConverter::class, OrderWasPlacedConverter::class],
            [new OrderService(), new PlaceOrderConverter(), new OrderWasPlacedConverter()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('orders', conversionMediaType: MediaType::createApplicationXPHPArray()),
                    PollingMetadata::create('orders')
                        ->withTestingSetup(2),
                ]),
            testConfiguration: TestConfiguration::createWithDefaults()
                ->withSpyOnChannel('orders'),
        );

        $orderId = 'someId';
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));

        $ecotoneTestSupport->getMessagingTestSupport()->discardRecordedMessages();
        $this->assertCount(
            0,
            $ecotoneTestSupport->getMessagingTestSupport()->getRecordedEcotoneMessagesFrom('orders')
        );

        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertEquals(
            ['orderId' => 'someId'],
            $ecotoneTestSupport->getMessagingTestSupport()->getRecordedEcotoneMessagesFrom('orders')[0]->getPayload()
        );

        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertEquals([$orderId], $ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getNotifiedOrders'));
    }

    public function test_collecting_published_events()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, ChannelConfiguration::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withEnvironment('test')
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders')),
        );

        $orderId = 'someId';
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));

        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $testSupportGateway = $ecotoneTestSupport->getMessagingTestSupport();

        $this->assertEquals([new OrderWasPlaced($orderId)], $testSupportGateway->getRecordedEvents());
        $this->assertEmpty($testSupportGateway->getRecordedEvents());
    }

    public function test_collecting_published_event_messages()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, ChannelConfiguration::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withEnvironment('test')
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders')),
        );

        $orderId = 'someId';
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));

        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $testSupportGateway = $ecotoneTestSupport->getMessagingTestSupport();

        $this->assertEquals(new OrderWasPlaced($orderId), $testSupportGateway->getRecordedEventMessages()[0]->getPayload());
        $this->assertEmpty($testSupportGateway->getRecordedEventMessages());
    }

    public function test_resetting_collected_messages()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, ChannelConfiguration::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withEnvironment('test')
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders')),
        );

        $orderId = 'someId';
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));

        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $testSupportGateway = $ecotoneTestSupport->getMessagingTestSupport();
        $testSupportGateway->discardRecordedMessages();

        $this->assertEmpty($testSupportGateway->getRecordedEventMessages());
    }

    public function test_collecting_sent_query_messages()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withEnvironment('test')
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders')),
            testConfiguration: TestConfiguration::createWithDefaults()->withFailOnQueryHandlerNotFound(false),
        );

        $ecotoneTestSupport->getQueryBus()->sendWithRouting('basket.getItem', new stdClass());

        $this->assertEquals(new stdClass(), $ecotoneTestSupport->getMessagingTestSupport()->getRecordedQueryMessages()[0]->getPayload());
    }

    public function test_collecting_sent_commands()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
        );

        $ecotoneTestSupport->getEventBus()->publish(new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderWasPlaced());

        $testSupportGateway = $ecotoneTestSupport->getMessagingTestSupport();

        $this->assertEquals([[], []], $testSupportGateway->getRecordedCommands());
        $this->assertEmpty($testSupportGateway->getRecordedCommands());
    }

    public function test_collecting_sent_command_messages()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
        );

        $ecotoneTestSupport->getEventBus()->publish(new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderWasPlaced());

        $testSupportGateway = $ecotoneTestSupport->getMessagingTestSupport();

        $this->assertEquals([], $testSupportGateway->getRecordedCommandMessages()[0]->getPayload());
        $this->assertEmpty($testSupportGateway->getRecordedCommandMessages());
    }

    public function test_command_bus_not_failing_in_test_mode_when_no_routing_command_found()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withEnvironment('test')
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders')),
            testConfiguration: TestConfiguration::createWithDefaults()->withFailOnCommandHandlerNotFound(false),
        );

        $command = new PlaceOrder('someId');
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('basket.addItem', $command);

        $this->assertEquals([$command], $ecotoneTestSupport->getMessagingTestSupport()->getRecordedCommands());
    }

    public function test_failing_command_bus_in_test_mode_when_no_routing_command_found()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withEnvironment('test')
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders')),
            testConfiguration: TestConfiguration::createWithDefaults()->withFailOnCommandHandlerNotFound(true),
        );

        $this->expectException(DestinationResolutionException::class);

        $ecotoneTestSupport->getCommandBus()->sendWithRouting('basket.addItem', new PlaceOrder('someId'));
    }

    public function test_query_bus_not_failing_in_test_mode_when_no_routing_command_found()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withEnvironment('test')
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders')),
            testConfiguration: TestConfiguration::createWithDefaults()->withFailOnQueryHandlerNotFound(false),
        );

        $ecotoneTestSupport->getQueryBus()->sendWithRouting('basket.getItem', new stdClass());

        $this->assertEquals([new stdClass()], $ecotoneTestSupport->getMessagingTestSupport()->getRecordedQueries());
    }

    public function test_failing_query_bus_in_test_mode_when_no_routing_command_found()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withEnvironment('test')
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders')),
            testConfiguration: TestConfiguration::createWithDefaults()->withFailOnQueryHandlerNotFound(true),
        );

        $this->expectException(DestinationResolutionException::class);

        $ecotoneTestSupport->getQueryBus()->sendWithRouting('basket.addItem', new PlaceOrder('someId'));
    }

    public function test_registering_in_memory_state_stored_repository()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [Order::class],
            [],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withEnvironment('test')
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders'))
                ->withExtensionObjects([
                    InMemoryRepositoryBuilder::createForAllStateStoredAggregates(),
                ]),
        );

        $ecotoneTestSupport->getCommandBus()->send(CreateOrderCommand::createWith(1, 1, 'some'));

        $this->assertEquals(
            'some',
            $ecotoneTestSupport->getQueryBus()->send(GetShippingAddressQuery::create(1))
        );

        $this->assertEquals([new Notification()], $ecotoneTestSupport->getMessagingTestSupport()->getRecordedEvents());
    }

    public function test_add_gateways_to_container()
    {
        $inMemoryPSRContainer = InMemoryPSRContainer::createFromAssociativeArray([
            OrderService::class => new OrderService(),
        ]);
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class],
            $inMemoryPSRContainer,
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders')),
            allowGatewaysToBeRegisteredInContainer: true
        );

        $orderId = '123';
        $inMemoryPSRContainer->get(CommandBus::class)->sendWithRouting('order.register', new PlaceOrder($orderId));

        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $testSupportGateway = $ecotoneTestSupport->getMessagingTestSupport();

        $this->assertEquals([new OrderWasPlaced($orderId)], $testSupportGateway->getRecordedEvents());
        $this->assertEmpty($testSupportGateway->getRecordedEvents());
    }

    public function test_making_use_of_cache()
    {
        $cacheDirectoryPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . Uuid::v7()->toRfc4122();
        $inMemoryPSRContainer = InMemoryPSRContainer::createFromAssociativeArray([
            OrderService::class => new OrderService(),
        ]);

        EcotoneLite::bootstrap(
            [OrderService::class],
            $inMemoryPSRContainer,
            ServiceConfiguration::createWithDefaults()
                ->withCacheDirectoryPath($cacheDirectoryPath)
                ->withModulePackages([])
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders')),
            useCachedVersion: true
        );

        $ecotoneLite = EcotoneLite::bootstrap(
            [OrderService::class],
            $inMemoryPSRContainer,
            ServiceConfiguration::createWithDefaults()
                ->withCacheDirectoryPath($cacheDirectoryPath)
                ->withModulePackages([])
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders')),
            useCachedVersion: true
        );

        $orderId = '123';
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));

        $ecotoneLite->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertNotEmpty($ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_releasing_delayed_message_time_time_span_object()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, PlaceOrderConverter::class, OrderWasPlacedConverter::class],
            [new OrderService(), new PlaceOrderConverter(), new OrderWasPlacedConverter()],
            configuration: ServiceConfiguration::createWithDefaults()->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders', true, MediaType::createApplicationXPHPArray()))
        );

        $orderId = 'someId';
        $ecotoneTestSupport->changeTimeTo(new DateTimeImmutable('2026-01-01 12:00:00'));
        $ecotoneTestSupport->sendCommandWithRoutingKey('order.register', new PlaceOrder($orderId), metadata: [
            MessageHeaders::DELIVERY_DELAY => TimeSpan::withHours(1),
        ]);

        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        $this->assertEquals([], $ecotoneTestSupport->sendQueryWithRouting('order.getNotifiedOrders'));

        $ecotoneTestSupport->advanceTimeBy(TimeSpan::withMinutes(59))->run('orders');
        $this->assertEquals([], $ecotoneTestSupport->sendQueryWithRouting('order.getNotifiedOrders'));

        $ecotoneTestSupport->advanceTimeBy(TimeSpan::withMinutes(1))->run('orders');
        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertEquals([$orderId], $ecotoneTestSupport->sendQueryWithRouting('order.getNotifiedOrders'));
    }

    public function test_delaying_till_specific_moment_in_time()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, PlaceOrderConverter::class, OrderWasPlacedConverter::class],
            [new OrderService(), new PlaceOrderConverter(), new OrderWasPlacedConverter()],
            configuration: ServiceConfiguration::createWithDefaults()->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders', true, MediaType::createApplicationXPHPArray()))
        );

        $orderId = 'someId';
        $ecotoneTestSupport->sendCommandWithRoutingKey('order.register', new PlaceOrder($orderId), metadata: [
            MessageHeaders::DELIVERY_DELAY => $delayTime = new DateTimeImmutable('+1 hour'),
        ]);

        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        $this->assertEquals([], $ecotoneTestSupport->sendQueryWithRouting('order.getNotifiedOrders'));

        $ecotoneTestSupport->changeTimeTo($delayTime->modify('-1 seconds'))->run('orders');
        $this->assertEquals([], $ecotoneTestSupport->sendQueryWithRouting('order.getNotifiedOrders'));

        $ecotoneTestSupport->changeTimeTo($delayTime)->run('orders');
        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertEquals([$orderId], $ecotoneTestSupport->sendQueryWithRouting('order.getNotifiedOrders'));
    }

    public function test_delaying_with_past_date_make_it_available_right_away(): void
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, PlaceOrderConverter::class, OrderWasPlacedConverter::class],
            [new OrderService(), new PlaceOrderConverter(), new OrderWasPlacedConverter()],
            configuration: ServiceConfiguration::createWithDefaults()->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders', true, MediaType::createApplicationXPHPArray()))
        );

        $orderId = 'someId';
        $ecotoneTestSupport->sendCommandWithRoutingKey('order.register', new PlaceOrder($orderId), metadata: [
            MessageHeaders::TIMESTAMP => ($time = new DatePoint('2020-01-01 12:00:00'))->unixTime()->inSeconds(),
            MessageHeaders::DELIVERY_DELAY => $time->modify('-1 hour'),
        ]);

        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertEquals([$orderId], $ecotoneTestSupport->sendQueryWithRouting('order.getNotifiedOrders'));
    }

    public function test_configured_queue_channel_is_in_memory_delayable(): void
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, PlaceOrderConverter::class, OrderWasPlacedConverter::class],
            [new OrderService(), new PlaceOrderConverter(), new OrderWasPlacedConverter()],
            configuration: ServiceConfiguration::createWithDefaults()->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders', true, MediaType::createApplicationXPHPArray()))
        );

        $orderId = 'someId';
        $ecotoneTestSupport->sendCommandWithRoutingKey('order.register', new PlaceOrder($orderId), metadata: [
            MessageHeaders::DELIVERY_DELAY => $delayTime = new DateTimeImmutable('+1 hour'),
        ]);

        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        $this->assertEquals([], $ecotoneTestSupport->sendQueryWithRouting('order.getNotifiedOrders'));

        $ecotoneTestSupport->changeTimeTo($delayTime->modify('-1 seconds'))->run('orders');
        $this->assertEquals([], $ecotoneTestSupport->sendQueryWithRouting('order.getNotifiedOrders'));

        $ecotoneTestSupport->changeTimeTo($delayTime)->run('orders');
        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertEquals([$orderId], $ecotoneTestSupport->sendQueryWithRouting('order.getNotifiedOrders'));
    }

    public function test_channel_provided_default_channel_is_not_used(): void
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, PlaceOrderConverter::class, OrderWasPlacedConverter::class],
            [new OrderService(), new PlaceOrderConverter(), new OrderWasPlacedConverter()],
            configuration: ServiceConfiguration::createWithDefaults()->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('orders', false))
        );

        $orderId = 'someId';
        $ecotoneTestSupport->sendCommandWithRoutingKey('order.register', new PlaceOrder($orderId), metadata: [
            MessageHeaders::DELIVERY_DELAY => new DateTimeImmutable('+1 hour'),
        ]);

        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        $ecotoneTestSupport->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertEquals([$orderId], $ecotoneTestSupport->sendQueryWithRouting('order.getNotifiedOrders'));
    }
}
