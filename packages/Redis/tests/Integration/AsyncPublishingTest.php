<?php

declare(strict_types=1);

namespace Test\Ecotone\Redis\Integration;

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
use Ecotone\Redis\Configuration\RedisMessagePublisherConfiguration;
use Ecotone\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Test\LicenceTesting;
use Enqueue\Redis\RedisConnectionFactory;
use Enqueue\Redis\RedisContext;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Redis\ConnectionTestCase;
use Test\Ecotone\Redis\Fixture\AsyncPublishing\OrderWasPlaced;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class AsyncPublishingTest extends ConnectionTestCase
{
    public function setUp(): void
    {
        /** @var RedisContext $context */
        $context = $this->getConnectionFactory()->createContext();
        $context->getRedis()->del('asyncOrdersChannel');
        $context->getRedis()->del('asyncOrdersChannel:delayed');
    }

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
            [RedisConnectionFactory::class => $this->getConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::REDIS_PACKAGE]))
                ->withExtensionObjects([
                    RedisMessagePublisherConfiguration::create(queueName: Uuid::v7()->toRfc4122())
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
            [RedisConnectionFactory::class => $this->getConnectionFactory(), $commandHandler],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::REDIS_PACKAGE]))
                ->withExtensionObjects([
                    RedisMessagePublisherConfiguration::create(queueName: $queueName)
                        ->withAsyncPublishing(),
                    RedisBackedMessageChannelBuilder::create($queueName),
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

    public function test_delayed_entry_of_published_batch_lands_in_delayed_set(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $messaging = $this->bootstrapPublisher($queueName, asyncPublishing: true);
        $publisher = $messaging->getGateway(MessagePublisher::class);

        $publisher->asyncPublish(
            BatchMessage::constructEmpty()
                ->append('immediate order')
                ->append('delayed order', [MessageHeaders::DELIVERY_DELAY => 60000])
        )->resolve();

        $receivedPayloads = [];
        while ($message = $messaging->getMessageChannel($queueName)->receive()) {
            $receivedPayloads[] = $message->getPayload();
        }
        $this->assertSame(['immediate order'], $receivedPayloads);

        /** @var RedisContext $context */
        $context = $this->getConnectionFactory()->createContext();
        $this->assertSame(1, $context->getRedis()->eval('return redis.call("zcard", KEYS[1])', [$queueName . ':delayed']));
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

    public function test_publishing_to_wrong_type_key_throws(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $messaging = $this->bootstrapPublisher($queueName, asyncPublishing: true);
        /** @var RedisContext $context */
        $context = $this->getConnectionFactory()->createContext();
        $context->getRedis()->eval('redis.call("set", KEYS[1], "blocked") return 1', [$queueName]);
        $publisher = $messaging->getGateway(MessagePublisher::class);

        $publishFailed = false;
        try {
            $publisher->asyncPublish(
                BatchMessage::constructEmpty()
                    ->append('first order')
                    ->append('second order')
            );
        } catch (Throwable) {
            $publishFailed = true;
        }

        $this->assertTrue($publishFailed);
    }

    public function test_partially_applied_mixed_batch_throws_while_immediate_entries_remain(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $messaging = $this->bootstrapPublisher($queueName, asyncPublishing: true);
        /** @var RedisContext $context */
        $context = $this->getConnectionFactory()->createContext();
        $context->getRedis()->lpush($queueName . ':delayed', 'poison entry forcing wrong type on the delayed set');
        $publisher = $messaging->getGateway(MessagePublisher::class);

        $publishFailed = false;
        try {
            $publisher->asyncPublish(
                BatchMessage::constructEmpty()
                    ->append('immediate order')
                    ->append('delayed order', [MessageHeaders::DELIVERY_DELAY => 60000])
            );
        } catch (Throwable) {
            $publishFailed = true;
        }

        $this->assertTrue($publishFailed);

        $context->getRedis()->del($queueName . ':delayed');
        $this->assertSame('immediate order', $messaging->getMessageChannel($queueName)->receive()->getPayload());
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
            #[EventHandler(endpointId: 'async_redis_order_collector')]
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
            [RedisConnectionFactory::class => $this->getConnectionFactory(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::REDIS_PACKAGE]))
                ->withExtensionObjects([
                    RedisBackedMessageChannelBuilder::create('asyncOrdersChannel')
                        ->withAsyncPublishing(),
                ]),
            licenceKey: $licenceKey,
        );
    }

    private function bootstrapPublisher(string $queueName, bool $asyncPublishing): FlowTestSupport
    {
        $publisherConfiguration = RedisMessagePublisherConfiguration::create(queueName: $queueName);
        if ($asyncPublishing) {
            $publisherConfiguration = $publisherConfiguration->withAsyncPublishing();
        }

        return EcotoneLite::bootstrapFlowTesting(
            [],
            [RedisConnectionFactory::class => $this->getConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::REDIS_PACKAGE]))
                ->withExtensionObjects([
                    $publisherConfiguration,
                    RedisBackedMessageChannelBuilder::create($queueName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
