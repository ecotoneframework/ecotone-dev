<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Amqp\Publisher\AmqpMessagePublisherConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\EventBus;
use Ecotone\Test\LicenceTesting;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnection;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\Amqp\Fixture\AsyncPublishing\OrderWasPlaced;

/**
 * licence Apache-2.0
 * @internal
 */
final class AsyncPublishingTest extends AmqpMessagingTestCase
{
    public function test_multiple_messages_published_asynchronously_from_command_handler_are_delivered(): void
    {
        $channelName = Uuid::v7()->toRfc4122();
        $orderService = $this->createOrderService($channelName);
        $messaging = $this->bootstrapEcotone($channelName, $orderService, LicenceTesting::VALID_LICENCE);

        $messaging->sendCommandWithRoutingKey('order.place', 'espresso');

        $this->assertSame([], $messaging->sendQueryWithRouting('order.getReceived'));

        $messaging->run('asyncOrdersChannel', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 3, maxExecutionTimeInMilliseconds: 10000));

        $this->assertCount(3, $messaging->sendQueryWithRouting('order.getReceived'));
    }

    public function test_async_publishing_requires_enterprise_licence(): void
    {
        $channelName = Uuid::v7()->toRfc4122();
        $orderService = $this->createOrderService($channelName);

        $this->expectException(LicensingException::class);

        $this->bootstrapEcotone($channelName, $orderService, licenceKey: null);
    }

    public function test_async_publishing_via_message_publisher_requires_enterprise_licence(): void
    {
        $this->expectException(LicensingException::class);

        EcotoneLite::bootstrapFlowTesting(
            [],
            [...$this->getConnectionFactoryReferences()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpMessagePublisherConfiguration::create()
                        ->withDefaultRoutingKey(Uuid::v7()->toRfc4122())
                        ->withAsyncPublishing(),
                ]),
        );
    }

    public function test_message_publisher_async_publish_confirms_delivery_on_future_resolve(): void
    {
        $messaging = EcotoneLite::bootstrapFlowTesting(
            [],
            [...$this->getConnectionFactoryReferences()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(true)
                        ->withDefaultRoutingKey(Uuid::v7()->toRfc4122())
                        ->withAsyncPublishing(),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $publisher = $messaging->getGateway(MessagePublisher::class);

        $singleFuture = $publisher->asyncPublish('single order');
        $batchFuture = $publisher->asyncPublish(
            BatchMessage::constructEmpty()
                ->append('first order')
                ->append('second order', ['priority' => '5'])
        );

        $this->assertNull($singleFuture->resolve());
        $this->assertNull($batchFuture->resolve());
    }

    public function test_batch_published_over_amqp_lib_connection_is_delivered(): void
    {
        $channelName = Uuid::v7()->toRfc4122();
        $orderService = $this->createOrderService($channelName);
        $libConnectionFactory = new AmqpLibConnection(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f']);
        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [
                AmqpConnectionFactory::class => $libConnectionFactory,
                AmqpLibConnection::class => $libConnectionFactory,
                $orderService,
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create('asyncOrdersChannel', queueName: $channelName)
                        ->withAsyncPublishing(),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.place', 'espresso');

        $messaging->run('asyncOrdersChannel', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 3, maxExecutionTimeInMilliseconds: 10000));

        $this->assertSame(
            ['espresso-1', 'espresso-2', 'espresso-3'],
            $messaging->sendQueryWithRouting('order.getReceived'),
        );
    }

    private function createOrderService(string $channelName): object
    {
        return new class ($channelName) {
            /** @var string[] */
            private array $receivedEvents = [];

            public function __construct(private string $channelName)
            {
            }

            #[CommandHandler('order.place')]
            public function placeOrder(string $order, EventBus $eventBus): void
            {
                $eventBus->publish(new OrderWasPlaced($order . '-1'));
                $eventBus->publish(new OrderWasPlaced($order . '-2'));
                $eventBus->publish(new OrderWasPlaced($order . '-3'));
            }

            #[Asynchronous('asyncOrdersChannel')]
            #[EventHandler(endpointId: 'async_amqp_order_collector')]
            public function collect(OrderWasPlaced $event): void
            {
                $this->receivedEvents[] = $event->order;
            }

            #[QueryHandler('order.getReceived')]
            public function getReceived(): array
            {
                return $this->receivedEvents;
            }
        };
    }

    private function bootstrapEcotone(string $channelName, object $orderService, ?string $licenceKey): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [...$this->getConnectionFactoryReferences(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create('asyncOrdersChannel', queueName: $channelName)
                        ->withAsyncPublishing(),
                ]),
            licenceKey: $licenceKey,
        );
    }
}
