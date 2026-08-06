<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Amqp\Publisher\AmqpMessagePublisherConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\AsyncPublishing\PublishingFailedException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Messaging\PollableChannel;
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

    public function test_async_publish_on_publisher_without_async_configuration_throws_before_publishing(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $messaging = EcotoneLite::bootstrapFlowTesting(
            [],
            [...$this->getConnectionFactoryReferences()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpMessagePublisherConfiguration::create()
                        ->withDefaultRoutingKey($queueName),
                    AmqpBackedMessageChannelBuilder::create('verificationChannel', queueName: $queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $publisher = $messaging->getGateway(MessagePublisher::class);

        $publishFailed = false;
        try {
            $publisher->asyncPublish('order that must not be published');
        } catch (PublishingFailedException) {
            $publishFailed = true;
        }

        $this->assertTrue($publishFailed);
        $this->assertNull($messaging->getMessageChannel('verificationChannel')->receiveWithTimeout(PollingMetadata::create('verification')->setFixedRateInMilliseconds(200)));
    }

    public function test_message_publisher_async_publish_confirms_delivery_on_future_resolve(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $context = self::getRabbitConnectionFactory()->createContext();
        $context->declareQueue($context->createQueue($queueName));
        $messaging = EcotoneLite::bootstrapFlowTesting(
            [],
            [...$this->getConnectionFactoryReferences()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(true)
                        ->withDefaultRoutingKey($queueName)
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
        $messaging = $this->bootstrapPublisherWithVerificationChannel($queueName, $commandHandler);

        $messaging->sendCommandWithRoutingKey('order.placeBatch', 'espresso');

        $verificationChannel = $messaging->getMessageChannel('verificationChannel');
        $receivedPayloads = [
            $verificationChannel->receive()->getPayload(),
            $verificationChannel->receive()->getPayload(),
        ];
        sort($receivedPayloads);
        $this->assertSame(['espresso first order', 'espresso second order'], $receivedPayloads);
    }

    public function test_delayed_entry_of_published_batch_is_delivered_after_delay(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $messaging = $this->bootstrapPublisherWithVerificationChannel($queueName);
        $publisher = $messaging->getGateway(MessagePublisher::class);

        $publisher->asyncPublish(
            BatchMessage::constructEmpty()
                ->append('immediate order')
                ->append('delayed order', [MessageHeaders::DELIVERY_DELAY => 2000])
        )->resolve();

        $verificationChannel = $messaging->getMessageChannel('verificationChannel');
        $this->assertSame('immediate order', $verificationChannel->receive()->getPayload());
        $this->assertNull($verificationChannel->receiveWithTimeout(PollingMetadata::create('assertNotYetDelivered')->setExecutionTimeLimitInMilliseconds(500)));

        $this->assertSame('delayed order', $this->receiveWithDeadline($verificationChannel, 10)?->getPayload());
    }

    public function test_expired_entry_of_published_batch_is_not_delivered(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $messaging = $this->bootstrapPublisherWithVerificationChannel($queueName);
        $publisher = $messaging->getGateway(MessagePublisher::class);

        $publisher->asyncPublish(
            BatchMessage::constructEmpty()
                ->append('expiring order', [MessageHeaders::TIME_TO_LIVE => 100])
                ->append('kept order')
        )->resolve();

        usleep(300000);

        $verificationChannel = $messaging->getMessageChannel('verificationChannel');
        $this->assertSame('kept order', $verificationChannel->receive()->getPayload());
        $this->assertNull($verificationChannel->receiveWithTimeout(PollingMetadata::create('assertExpired')->setExecutionTimeLimitInMilliseconds(500)));
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
                        ->withHighThroughputPublishing(),
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

    private function bootstrapPublisherWithVerificationChannel(string $queueName, ?object $commandHandler = null): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            $commandHandler === null ? [] : [$commandHandler::class],
            $commandHandler === null
                ? [...$this->getConnectionFactoryReferences()]
                : [...$this->getConnectionFactoryReferences(), $commandHandler],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpMessagePublisherConfiguration::create()
                        ->withDefaultRoutingKey($queueName)
                        ->withAsyncPublishing(),
                    AmqpBackedMessageChannelBuilder::create('verificationChannel', queueName: $queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
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

    private function bootstrapEcotone(string $channelName, object $orderService, ?string $licenceKey): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [...$this->getConnectionFactoryReferences(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create('asyncOrdersChannel', queueName: $channelName)
                        ->withHighThroughputPublishing(),
                ]),
            licenceKey: $licenceKey,
        );
    }
}
