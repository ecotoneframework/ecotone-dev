<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Kafka\Attribute\KafkaConsumer;
use Ecotone\Kafka\Channel\KafkaMessageChannelBuilder;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Kafka\Configuration\KafkaPublisherConfiguration;
use Ecotone\Kafka\Configuration\TopicConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\AsyncPublishing\PublishingFailedException;
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
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;
use Test\Ecotone\Kafka\Fixture\Handler\ExampleEvent;

/**
 * licence Enterprise
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class AsyncPublishingTest extends TestCase
{
    public function test_multiple_messages_published_asynchronously_from_command_handler_are_delivered(): void
    {
        $channelName = 'async_orders';
        $orderService = $this->createOrderService($channelName);
        $messaging = $this->bootstrapEcotone($channelName, $orderService, ConnectionTestCase::getConnection());

        $messaging->sendCommandWithRoutingKey('order.place', 'espresso');

        $this->assertSame([], $messaging->sendQueryWithRouting('order.getReceived'));

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 3, maxExecutionTimeInMilliseconds: 10000));

        $this->assertCount(3, $messaging->sendQueryWithRouting('order.getReceived'));
    }

    public function test_failing_to_deliver_asynchronously_published_messages_throws(): void
    {
        $channelName = 'async_orders';
        $orderService = $this->createOrderService($channelName);
        $messaging = $this->bootstrapEcotone(
            $channelName,
            $orderService,
            KafkaBrokerConfiguration::createWithDefaults(['wronghost:9092']),
            asyncPublishingTimeout: 500,
        );

        $this->expectException(PublishingFailedException::class);

        $messaging->sendCommandWithRoutingKey('order.place', 'espresso');
    }

    public function test_async_publishing_requires_enterprise_licence(): void
    {
        $this->expectException(LicensingException::class);

        EcotoneLite::bootstrapFlowTesting(
            [],
            [KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaPublisherConfiguration::createWithDefaults(topicName: Uuid::v7()->toRfc4122())
                        ->withAsyncPublishing(),
                ]),
        );
    }

    public function test_async_publishing_via_message_channel_requires_enterprise_licence(): void
    {
        $this->expectException(LicensingException::class);

        EcotoneLite::bootstrapFlowTesting(
            [],
            [KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(
                        'async_orders',
                        topicName: $uniqueId = Uuid::v7()->toRfc4122(),
                        messageGroupId: $uniqueId,
                    )->withHighThroughputPublishing(),
                ]),
        );
    }

    public function test_message_publisher_async_publish_confirms_delivery_on_future_resolve(): void
    {
        $messaging = EcotoneLite::bootstrapFlowTesting(
            [],
            [KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaPublisherConfiguration::createWithDefaults(topicName: Uuid::v7()->toRfc4122())
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

    public function test_batch_message_published_synchronously_from_command_handler_is_delivered(): void
    {
        $topicName = Uuid::v7()->toRfc4122();
        $commandHandler = new class () {
            /** @var string[] */
            private array $receivedPayloads = [];

            #[CommandHandler('order.placeBatch')]
            public function handle(string $order, #[Reference(MessagePublisher::class)] MessagePublisher $publisher): void
            {
                $publisher->convertAndSend(
                    BatchMessage::constructEmpty()
                        ->append($order . ' first order')
                        ->append($order . ' second order')
                );
            }

            #[KafkaConsumer('batchOrdersConsumer', 'batchOrdersTopic')]
            public function collect(string $payload): void
            {
                $this->receivedPayloads[] = $payload;
            }

            #[QueryHandler('order.getReceivedBatchOrders')]
            public function getReceived(): array
            {
                return $this->receivedPayloads;
            }
        };
        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$commandHandler::class],
            [KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(), $commandHandler],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaPublisherConfiguration::createWithDefaults(topicName: $topicName)
                        ->withAsyncPublishing(),
                    TopicConfiguration::createWithReferenceName('batchOrdersTopic', $topicName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.placeBatch', 'espresso');

        $messaging->run('batchOrdersConsumer', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 2, maxExecutionTimeInMilliseconds: 30000));

        $receivedPayloads = $messaging->sendQueryWithRouting('order.getReceivedBatchOrders');
        sort($receivedPayloads);
        $this->assertSame(['espresso first order', 'espresso second order'], $receivedPayloads);
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
                $eventBus->publish(new ExampleEvent($order . '-1'));
                $eventBus->publish(new ExampleEvent($order . '-2'));
                $eventBus->publish(new ExampleEvent($order . '-3'));
            }

            #[Asynchronous('async_orders')]
            #[EventHandler(endpointId: 'async_order_collector')]
            public function collect(ExampleEvent $event): void
            {
                $this->receivedEvents[] = $event->id;
            }

            #[QueryHandler('order.getReceived')]
            public function getReceived(): array
            {
                return $this->receivedEvents;
            }
        };
    }

    private function bootstrapEcotone(string $channelName, object $orderService, KafkaBrokerConfiguration $brokerConfiguration, ?int $asyncPublishingTimeout = null): FlowTestSupport
    {
        $channelBuilder = KafkaMessageChannelBuilder::create(
            $channelName,
            topicName: $uniqueId = Uuid::v7()->toRfc4122(),
            messageGroupId: $uniqueId,
        )->withHighThroughputPublishing();

        if ($asyncPublishingTimeout !== null) {
            $channelBuilder = $channelBuilder->withHighThroughputPublishing(timeoutInMilliseconds: $asyncPublishingTimeout);
        }

        return EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [KafkaBrokerConfiguration::class => $brokerConfiguration, $orderService],
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([$channelBuilder]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
