<?php

namespace Test\Ecotone\Lite\Test;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Lite\Test\Configuration\InMemoryStateStoredRepositoryBuilder;
use Ecotone\Lite\Test\TestConfiguration;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\ConversionException;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\DestinationResolutionException;
use Ecotone\Modelling\CommandBus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
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

final class EcotoneLiteTest extends TestCase
{
    public function test_bootstraping_with_given_set_of_classes()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [OrderService::class, ChannelConfiguration::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages())
                ->withEnvironment("test"),
        );

        $orderId = "someId";
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));

        $this->assertNotEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_bootstraping_with_namespace()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [],
            [new OrderService(), new PlaceOrderConverter(), new OrderWasPlacedConverter()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages())
                ->withEnvironment("test")
                ->withNamespaces(["Test\Ecotone\Modelling\Fixture\Order"]),
            pathToRootCatalog: __DIR__ . '/../../'
        );

        $orderId = "someId";
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));

        $this->assertNotEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_bootstraping_with_given_set_of_classes_and_asynchronous_module()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [OrderService::class, ChannelConfiguration::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
        );

        $orderId = "someId";
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));
        $this->assertEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));

        $ecotoneTestSupport->run("orders");
        $this->assertNotEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_sending_command_which_requires_serialization()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [OrderService::class, PlaceOrderConverter::class],
            [new OrderService(), new PlaceOrderConverter()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages())
                ->withEnvironment("test"),
        );

        $orderId = "someId";
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', ["orderId" => $orderId]);

        $this->assertNotEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_sending_command_which_requires_serialization_with_converter_by_class()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [OrderService::class, ChannelConfiguration::class, PlaceOrderConverter::class],
            [new OrderService(), new PlaceOrderConverter()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages())
                ->withEnvironment("test"),
        );

        $orderId = "someId";
        $ecotoneTestSupport->getCommandBus()->sendWithRouting(PlaceOrder::class, ["orderId" => $orderId]);

        $this->assertNotEmpty($ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_failing_serializing_command_message_due_to_lack_of_converter()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('orders'),
                    PollingMetadata::create('orders')
                        ->withTestingSetup(2),
                    TestConfiguration::createWithDefaults()
                        ->withMediaTypeConversion("orders", MediaType::createApplicationXPHPArray())
                ]),
        );

        /** Failing on command serialization */
        $this->expectException(ConversionException::class);

        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder("someId"));
    }

    public function test_failing_serializing_event_message_due_to_lack_of_converter()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [OrderService::class, PlaceOrderConverter::class],
            [new OrderService(), new PlaceOrderConverter(), "logger" => new NullLogger()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('orders'),
                    PollingMetadata::create('orders')
                        ->withTestingSetup(1),
                    TestConfiguration::createWithDefaults()
                        ->withMediaTypeConversion("orders", MediaType::createApplicationXPHPArray())
                ]),
        );

        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder("someId"));

        /** Failing on event serialization */
        $this->expectException(ConversionException::class);

        $ecotoneTestSupport->run("orders");
    }

    public function test_serializing_command_and_event_before_sending_to_asynchronous_channel()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [OrderService::class, PlaceOrderConverter::class, OrderWasPlacedConverter::class],
            [new OrderService(), new PlaceOrderConverter(), new OrderWasPlacedConverter()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('orders'),
                    PollingMetadata::create('orders')
                        ->withTestingSetup(2),
                    TestConfiguration::createWithDefaults()
                        ->withMediaTypeConversion("orders", MediaType::createApplicationXPHPArray())
                ]),
        );

        $orderId = "someId";
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));

        $ecotoneTestSupport->run("orders");

        $this->assertEquals([$orderId], $ecotoneTestSupport->getQueryBus()->sendWithRouting('order.getNotifiedOrders'));
    }

    public function test_collecting_sent_events()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [OrderService::class, ChannelConfiguration::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages())
                ->withEnvironment("test"),
        );

        $orderId = "someId";
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));

        $testSupportGateway = $ecotoneTestSupport->getTestSupportGateway();

        $this->assertEquals([new OrderWasPlaced($orderId)], $testSupportGateway->getPublishedEvents());
        $this->assertEmpty($testSupportGateway->getPublishedEvents());
    }

    public function test_collecting_sent_event_messages()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [OrderService::class, ChannelConfiguration::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages())
                ->withEnvironment("test"),
        );

        $orderId = "someId";
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));

        $testSupportGateway = $ecotoneTestSupport->getTestSupportGateway();

        $this->assertEquals(new OrderWasPlaced($orderId), $testSupportGateway->getPublishedEventMessages()[0]->getPayload());
        $this->assertEmpty($testSupportGateway->getPublishedEventMessages());
    }

    public function test_collecting_sent_commands()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages())
        );

        $ecotoneTestSupport->getEventBus()->publish(new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderWasPlaced());

        $testSupportGateway = $ecotoneTestSupport->getTestSupportGateway();

        $this->assertEquals([[]], $testSupportGateway->getSentCommands());
        $this->assertEmpty($testSupportGateway->getSentCommands());
    }

    public function test_collecting_sent_command_messages()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages())
        );

        $ecotoneTestSupport->getEventBus()->publish(new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderWasPlaced());

        $testSupportGateway = $ecotoneTestSupport->getTestSupportGateway();

        $this->assertEquals([], $testSupportGateway->getSentCommandMessages()[0]->getPayload());
        $this->assertEmpty($testSupportGateway->getSentCommandMessages());
    }

    public function test_not_command_bus_failing_in_test_mode_when_no_routing_command_found()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages())
                ->withEnvironment("test")
                ->withExtensionObjects([
                    TestConfiguration::createWithDefaults()->withFailOnCommandHandlerNotFound(false)
                ]),
        );

        $command = new PlaceOrder("someId");
        $ecotoneTestSupport->getCommandBus()->sendWithRouting('basket.addItem', $command);

        $this->assertEquals([$command], $ecotoneTestSupport->getTestSupportGateway()->getSentCommands());
    }

    public function test_failing_command_bus_in_test_mode_when_no_routing_command_found()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages())
                ->withEnvironment("test")
                ->withExtensionObjects([
                    TestConfiguration::createWithDefaults()->withFailOnCommandHandlerNotFound(true)
                ]),
        );

        $this->expectException(DestinationResolutionException::class);

        $ecotoneTestSupport->getCommandBus()->sendWithRouting('basket.addItem', new PlaceOrder("someId"));
    }

    public function test_not_query_bus_failing_in_test_mode_when_no_routing_command_found()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages())
                ->withEnvironment("test")
                ->withExtensionObjects([
                    TestConfiguration::createWithDefaults()->withFailOnQueryHandlerNotFound(false)
                ]),
        );

        $ecotoneTestSupport->getQueryBus()->sendWithRouting('basket.getItem', new \stdClass());

        $this->assertEquals([new \stdClass()], $ecotoneTestSupport->getTestSupportGateway()->getSentQueries());
    }

    public function test_failing_query_bus_in_test_mode_when_no_routing_command_found()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [\Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService::class],
            [new \Test\Ecotone\Modelling\Fixture\MetadataPropagating\OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages())
                ->withEnvironment("test")
                ->withExtensionObjects([
                    TestConfiguration::createWithDefaults()->withFailOnQueryHandlerNotFound(true)
                ]),
        );

        $this->expectException(DestinationResolutionException::class);

        $ecotoneTestSupport->getQueryBus()->sendWithRouting('basket.addItem', new PlaceOrder("someId"));
    }

    public function test_registering_in_memory_state_stored_repository()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [Order::class],
            [],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages())
                ->withEnvironment("test")
                ->withExtensionObjects([
                    InMemoryStateStoredRepositoryBuilder::createForAllAggregates()
                ]),
        );

        $ecotoneTestSupport->getCommandBus()->send(CreateOrderCommand::createWith(1, 1, "some"));

        $this->assertEquals(
            "some",
            $ecotoneTestSupport->getQueryBus()->send(GetShippingAddressQuery::create(1))
        );

        $this->assertEquals([new Notification()], $ecotoneTestSupport->getTestSupportGateway()->getPublishedEvents());
    }

    public function test_add_gateways_to_container()
    {
        $inMemoryPSRContainer = InMemoryPSRContainer::createFromAssociativeArray([
            OrderService::class => new OrderService()
        ]);
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            $inMemoryPSRContainer,
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages()),
            allowGatewaysToBeRegisteredInContainer: true
        );

        $orderId = "123";
        $inMemoryPSRContainer->get(CommandBus::class)->sendWithRouting('order.register', new PlaceOrder($orderId));

        $testSupportGateway = $ecotoneTestSupport->getTestSupportGateway();

        $this->assertEquals([new OrderWasPlaced($orderId)], $testSupportGateway->getPublishedEvents());
        $this->assertEmpty($testSupportGateway->getPublishedEvents());
    }

    public function test_making_use_of_cache()
    {
        $cacheDirectoryPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . Uuid::uuid4()->toString();
        $inMemoryPSRContainer = InMemoryPSRContainer::createFromAssociativeArray([
            OrderService::class => new OrderService()
        ]);

//        cache
        EcotoneLite::bootstrap(
            [OrderService::class],
            $inMemoryPSRContainer,
            ServiceConfiguration::createWithDefaults()
                ->withCacheDirectoryPath($cacheDirectoryPath)
                ->withSkippedModulePackageNames(ModulePackageList::allPackages()),
            useCachedVersion: true
        );

//        resolve cache
        $ecotoneLite = EcotoneLite::bootstrap(
            [OrderService::class],
            $inMemoryPSRContainer,
            ServiceConfiguration::createWithDefaults()
                ->withCacheDirectoryPath($cacheDirectoryPath)
                ->withSkippedModulePackageNames(ModulePackageList::allPackages()),
            useCachedVersion: true
        );

        $orderId = "123";
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', new PlaceOrder($orderId));

        $this->assertNotEmpty($ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));
    }
}
