<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\AsyncPublishing\PublishingFailedException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\EventBus;
use Ecotone\Sqs\Configuration\SqsMessagePublisherConfiguration;
use Ecotone\Sqs\SqsBackedMessageChannelBuilder;
use Ecotone\Test\LicenceTesting;
use Enqueue\Sqs\SqsConnectionFactory;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Sqs\ConnectionTestCase;
use Test\Ecotone\Sqs\Fixture\AsyncPublishing\OrderWasPlaced;

/**
 * licence Apache-2.0
 * @internal
 */
final class AsyncPublishingTest extends ConnectionTestCase
{
    public function test_multiple_messages_published_asynchronously_from_command_handler_are_delivered(): void
    {
        $orderService = $this->createOrderService();
        $messaging = $this->bootstrapEcotoneWithChannel($orderService, LicenceTesting::VALID_LICENCE);

        $messaging->sendCommandWithRoutingKey('order.place', 'espresso');

        $this->assertSame([], $messaging->sendQueryWithRouting('order.getReceived'));

        $messaging->run('asyncOrdersChannel', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 3, maxExecutionTimeInMilliseconds: 20000));

        $receivedEvents = $messaging->sendQueryWithRouting('order.getReceived');
        sort($receivedEvents);
        $this->assertSame(['espresso-1', 'espresso-2', 'espresso-3'], $receivedEvents);
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
            [SqsConnectionFactory::class => $this->getConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::SQS_PACKAGE]))
                ->withExtensionObjects([
                    SqsMessagePublisherConfiguration::create(queueName: Uuid::v7()->toRfc4122())
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
            [SqsConnectionFactory::class => $this->getConnectionFactory(), $commandHandler],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::SQS_PACKAGE]))
                ->withExtensionObjects([
                    SqsMessagePublisherConfiguration::create(queueName: $queueName)
                        ->withAsyncPublishing(timeoutInMilliseconds: 10000),
                    SqsBackedMessageChannelBuilder::create($queueName),
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

    public function test_batch_larger_than_ten_messages_is_chunked_and_delivered(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $messaging = $this->bootstrapPublisher($queueName, asyncPublishing: true);
        $publisher = $messaging->getGateway(MessagePublisher::class);

        $batch = BatchMessage::constructEmpty();
        for ($orderNumber = 1; $orderNumber <= 25; $orderNumber++) {
            $batch = $batch->append('order ' . $orderNumber);
        }

        $this->assertNull($publisher->asyncPublish($batch)->resolve());

        $receivedPayloads = [];
        while ($message = $messaging->getMessageChannel($queueName)->receive()) {
            $receivedPayloads[] = $message->getPayload();
        }
        $this->assertCount(25, $receivedPayloads);
    }

    public function test_delayed_entry_of_published_batch_is_delivered_after_delay(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $messaging = $this->bootstrapPublisher($queueName, asyncPublishing: true);
        $publisher = $messaging->getGateway(MessagePublisher::class);

        $publishedAt = microtime(true);
        $publisher->asyncPublish(
            BatchMessage::constructEmpty()
                ->append('immediate order')
                ->append('delayed order', [MessageHeaders::DELIVERY_DELAY => 1000])
        )->resolve();

        $channel = $messaging->getMessageChannel($queueName);
        $receivedAt = [];
        $deadline = microtime(true) + 15;
        while (count($receivedAt) < 2 && microtime(true) < $deadline) {
            if ($message = $channel->receive()) {
                $receivedAt[$message->getPayload()] = microtime(true);
            } else {
                usleep(100000);
            }
        }

        $this->assertArrayHasKey('immediate order', $receivedAt);
        $this->assertArrayHasKey('delayed order', $receivedAt);
        $this->assertGreaterThanOrEqual(1.0, $receivedAt['delayed order'] - $publishedAt);
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
            #[EventHandler(endpointId: 'async_sqs_order_collector')]
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
            [SqsConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::SQS_PACKAGE]))
                ->withExtensionObjects([
                    SqsBackedMessageChannelBuilder::create('asyncOrdersChannel')
                        ->withAsyncPublishing(),
                ]),
            licenceKey: $licenceKey,
        );
    }

    private function bootstrapPublisher(string $queueName, bool $asyncPublishing): FlowTestSupport
    {
        $publisherConfiguration = SqsMessagePublisherConfiguration::create(queueName: $queueName);
        if ($asyncPublishing) {
            $publisherConfiguration = $publisherConfiguration->withAsyncPublishing();
        }

        return EcotoneLite::bootstrapFlowTesting(
            [],
            [SqsConnectionFactory::class => $this->getConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::SQS_PACKAGE]))
                ->withExtensionObjects([
                    $publisherConfiguration,
                    SqsBackedMessageChannelBuilder::create($queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
