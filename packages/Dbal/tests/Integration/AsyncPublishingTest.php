<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\Configuration\DbalMessagePublisherConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\AsyncPublishing\PublishingFailedException;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\EventBus;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use Interop\Queue\Exception\Exception;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\AsyncPublishing\OrderWasPlaced;

/**
 * licence Apache-2.0
 * @internal
 */
final class AsyncPublishingTest extends DbalMessagingTestCase
{
    public function test_multiple_messages_published_asynchronously_from_command_handler_are_delivered(): void
    {
        $orderService = $this->createOrderService();
        $messaging = $this->bootstrapEcotoneWithChannel($orderService, LicenceTesting::VALID_LICENCE);

        $messaging->sendCommandWithRoutingKey('order.place', 'espresso');

        $this->assertSame([], $messaging->sendQueryWithRouting('order.getReceived'));

        $messaging->run('asyncOrdersChannel', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 3, maxExecutionTimeInMilliseconds: 10000));

        $this->assertSame(
            ['espresso-1', 'espresso-2', 'espresso-3'],
            $messaging->sendQueryWithRouting('order.getReceived'),
        );
    }

    public function test_async_publishing_requires_enterprise_licence(): void
    {
        $orderService = $this->createOrderService();

        $this->expectException(LicensingException::class);

        $this->bootstrapEcotoneWithChannel($orderService, licenceKey: null);
    }

    public function test_async_publishing_via_message_publisher_requires_enterprise_licence(): void
    {
        $this->expectException(LicensingException::class);

        EcotoneLite::bootstrapFlowTesting(
            [],
            [DbalConnectionFactory::class => $this->getConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalMessagePublisherConfiguration::create(MessagePublisher::class, Uuid::v7()->toRfc4122())
                        ->withAsyncPublishing(),
                ]),
        );
    }

    public function test_async_publish_on_publisher_without_async_configuration_throws_before_publishing(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $messaging = $this->bootstrapPublisher($queueName, asyncPublishing: false);
        $publisher = $messaging->getGateway(MessagePublisher::class);

        $publishFailed = false;
        try {
            $publisher->asyncPublish('order that must not be published');
        } catch (PublishingFailedException) {
            $publishFailed = true;
        }

        $this->assertTrue($publishFailed);
        $this->assertNull($messaging->getMessageChannel($queueName)->receive());
    }

    public function test_message_publisher_async_publish_confirms_delivery_on_future_resolve(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $messaging = $this->bootstrapPublisher($queueName, asyncPublishing: true);
        $publisher = $messaging->getGateway(MessagePublisher::class);

        $singleFuture = $publisher->asyncPublish('single order');
        $batchFuture = $publisher->asyncPublish(
            BatchMessage::constructEmpty()
                ->append('first order')
                ->append('second order', ['priority' => '5'])
        );

        $this->assertNull($singleFuture->resolve());
        $this->assertNull($batchFuture->resolve());

        $receivedPayloads = [];
        while ($message = $messaging->getMessageChannel($queueName)->receive()) {
            $receivedPayloads[] = $message->getPayload();
        }
        sort($receivedPayloads);
        $this->assertSame(['first order', 'second order', 'single order'], $receivedPayloads);
    }

    public function test_sending_batch_message_over_channel_without_async_publishing_throws(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $messaging = $this->bootstrapPublisher($queueName, asyncPublishing: false);

        $this->expectException(ConfigurationException::class);

        $messaging->getMessageChannel($queueName)->send(
            MessageBuilder::withPayload(BatchMessage::constructEmpty()->append('first order'))->build()
        );
    }

    public function test_sending_batch_message_via_publisher_without_async_publishing_throws(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $messaging = $this->bootstrapPublisher($queueName, asyncPublishing: false);
        $publisher = $messaging->getGateway(MessagePublisher::class);

        $this->expectException(ConfigurationException::class);

        $publisher->convertAndSend(BatchMessage::constructEmpty()->append('first order'));
    }

    public function test_batch_message_published_synchronously_from_command_handler_is_delivered(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $commandHandler = new class () {
            #[CommandHandler('order.placeBatch')]
            public function handle(string $order, #[Reference(MessagePublisher::class)] MessagePublisher $publisher): void
            {
                $publisher->convertAndSend(
                    BatchMessage::constructEmpty()
                        ->append($order . ' first order')
                        ->append($order . ' second order')
                );
            }
        };
        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$commandHandler::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $commandHandler],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalMessagePublisherConfiguration::create(MessagePublisher::class, $queueName)
                        ->withAsyncPublishing(),
                    DbalBackedMessageChannelBuilder::create($queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.placeBatch', 'espresso');

        $receivedPayloads = [];
        while ($message = $messaging->getMessageChannel($queueName)->receive()) {
            $receivedPayloads[] = $message->getPayload();
        }
        sort($receivedPayloads);
        $this->assertSame(['espresso first order', 'espresso second order'], $receivedPayloads);
    }

    public function test_delayed_entry_of_published_batch_is_delivered_after_delay(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $messaging = $this->bootstrapPublisher($queueName, asyncPublishing: true);
        $publisher = $messaging->getGateway(MessagePublisher::class);

        $publisher->asyncPublish(
            BatchMessage::constructEmpty()
                ->append('immediate order')
                ->append('delayed order', [MessageHeaders::DELIVERY_DELAY => 3000])
        )->resolve();

        $channel = $messaging->getMessageChannel($queueName);
        $this->assertSame('immediate order', $channel->receive()->getPayload());
        $this->assertNull($channel->receiveWithTimeout(PollingMetadata::create('assertNotYetDelivered')->setExecutionTimeLimitInMilliseconds(500)));

        $this->assertSame('delayed order', $this->receiveWithDeadline($channel, 10)?->getPayload());
    }

    public function test_expired_entry_of_published_batch_is_not_delivered(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $messaging = $this->bootstrapPublisher($queueName, asyncPublishing: true);
        $publisher = $messaging->getGateway(MessagePublisher::class);

        $publisher->asyncPublish(
            BatchMessage::constructEmpty()
                ->append('expiring order', [MessageHeaders::TIME_TO_LIVE => 1000])
                ->append('kept order')
        )->resolve();

        sleep(2);

        $channel = $messaging->getMessageChannel($queueName);
        $this->assertSame('kept order', $channel->receive()->getPayload());
        $this->assertNull($channel->receive());
    }

    public function test_publishing_after_queue_table_is_dropped_throws(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $messaging = $this->bootstrapPublisher($queueName, asyncPublishing: true);
        $publisher = $messaging->getGateway(MessagePublisher::class);
        $publisher->asyncPublish('first order')->resolve();

        $this->getConnection()->executeStatement('DROP TABLE enqueue');

        $this->expectException(Exception::class);

        $publisher->asyncPublish('order published into missing table');
    }

    private function receiveWithDeadline(PollableChannel $channel, int $deadlineInSeconds): ?Message
    {
        $deadline = microtime(true) + $deadlineInSeconds;
        while (microtime(true) < $deadline) {
            if ($message = $channel->receive()) {
                return $message;
            }
            usleep(100000);
        }

        return null;
    }

    private function createOrderService(): object
    {
        return new class () {
            /** @var string[] */
            private array $receivedEvents = [];

            #[CommandHandler('order.place')]
            public function placeOrder(string $order, EventBus $eventBus): void
            {
                $eventBus->publish(new OrderWasPlaced($order . '-1'));
                $eventBus->publish(new OrderWasPlaced($order . '-2'));
                $eventBus->publish(new OrderWasPlaced($order . '-3'));
            }

            #[Asynchronous('asyncOrdersChannel')]
            #[EventHandler(endpointId: 'async_dbal_order_collector')]
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

    private function bootstrapEcotoneWithChannel(object $orderService, ?string $licenceKey): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [DbalConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalBackedMessageChannelBuilder::create('asyncOrdersChannel')
                        ->withHighThroughputPublishing(),
                ]),
            licenceKey: $licenceKey,
        );
    }

    private function bootstrapPublisher(string $queueName, bool $asyncPublishing): FlowTestSupport
    {
        $publisherConfiguration = DbalMessagePublisherConfiguration::create(MessagePublisher::class, $queueName);
        if ($asyncPublishing) {
            $publisherConfiguration = $publisherConfiguration->withAsyncPublishing();
        }

        return EcotoneLite::bootstrapFlowTesting(
            [],
            [DbalConnectionFactory::class => $this->getConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    $publisherConfiguration,
                    DbalBackedMessageChannelBuilder::create($queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
