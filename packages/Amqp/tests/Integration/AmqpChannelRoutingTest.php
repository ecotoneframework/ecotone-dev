<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use AMQPQueueException;
use Ecotone\Amqp\AmqpBinding;
use Ecotone\Amqp\AmqpExchange;
use Ecotone\Amqp\AmqpQueue;
use Ecotone\Api\Amqp\AmqpMessagePublisherConfiguration;
use Ecotone\Api\Amqp\RabbitConsumer;
use Ecotone\Api\ExecutionPollingMetadata;
use Ecotone\Api\Payload;
use Ecotone\Api\ServiceConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class AmqpChannelRoutingTest extends AmqpMessagingTestCase
{
    public const CUSTOM_EXCHANGE = 'lite_test_custom_exchange';
    public const CUSTOM_EXCHANGE_QUEUE = 'lite_test_custom_exchange_queue';
    public const CUSTOM_EXCHANGE_ROUTING_KEY = 'orders.created';

    public const TOPIC_EXCHANGE = 'lite_test_topic_exchange';
    public const TOPIC_WHITE_QUEUE = 'lite_test_topic_white_queue';
    public const TOPIC_BLACK_QUEUE = 'lite_test_topic_black_queue';

    public const FANOUT_EXCHANGE = 'lite_test_fanout_exchange';
    public const FANOUT_QUEUE_ONE = 'lite_test_fanout_queue_one';
    public const FANOUT_QUEUE_TWO = 'lite_test_fanout_queue_two';

    public function tearDown(): void
    {
        foreach ([self::CUSTOM_EXCHANGE_QUEUE, self::TOPIC_WHITE_QUEUE, self::TOPIC_BLACK_QUEUE, self::FANOUT_QUEUE_ONE, self::FANOUT_QUEUE_TWO] as $queueName) {
            try {
                self::getRabbitConnectionFactory()->createContext()->deleteQueue(new \Interop\Amqp\Impl\AmqpQueue($queueName));
            } catch (AMQPQueueException) {
            }
        }
        parent::tearDown();
    }

    public function test_sending_to_a_custom_exchange_with_an_explicit_routing_key(): void
    {
        $handler = new CustomExchangeConsumer();

        $ecotoneLite = $this->bootstrapFlowTesting(
            [CustomExchangeConsumer::class],
            [
                $handler,
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::AMQP_PACKAGE])
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withExtensionObjects([
                    AmqpExchange::createDirectExchange(self::CUSTOM_EXCHANGE),
                    AmqpQueue::createWith(self::CUSTOM_EXCHANGE_QUEUE),
                    AmqpBinding::createFromNames(self::CUSTOM_EXCHANGE, self::CUSTOM_EXCHANGE_QUEUE, self::CUSTOM_EXCHANGE_ROUTING_KEY),
                    AmqpMessagePublisherConfiguration::create(exchangeName: self::CUSTOM_EXCHANGE)
                        ->withDefaultRoutingKey(self::CUSTOM_EXCHANGE_ROUTING_KEY),
                ])
        );

        $ecotoneLite->getMessagePublisher()->send('some');
        $ecotoneLite->run(CustomExchangeConsumer::ENDPOINT_ID, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup());

        $this->assertSame(['some'], $handler->received);
    }

    public function test_a_topic_exchange_routes_only_to_bindings_matching_the_routing_key(): void
    {
        $handler = new TopicExchangeConsumers();

        $ecotoneLite = $this->bootstrapFlowTesting(
            [TopicExchangeConsumers::class],
            [
                $handler,
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::AMQP_PACKAGE])
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withExtensionObjects([
                    AmqpExchange::createTopicExchange(self::TOPIC_EXCHANGE),
                    AmqpQueue::createWith(self::TOPIC_WHITE_QUEUE),
                    AmqpQueue::createWith(self::TOPIC_BLACK_QUEUE),
                    AmqpBinding::createFromNames(self::TOPIC_EXCHANGE, self::TOPIC_WHITE_QUEUE, '*.white'),
                    AmqpBinding::createFromNames(self::TOPIC_EXCHANGE, self::TOPIC_BLACK_QUEUE, '*.black'),
                    AmqpMessagePublisherConfiguration::create(exchangeName: self::TOPIC_EXCHANGE)
                        ->withDefaultRoutingKey('color.white'),
                ])
        );

        $ecotoneLite->getMessagePublisher()->send('some');

        $ecotoneLite->run(TopicExchangeConsumers::WHITE_ENDPOINT_ID, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup());
        $ecotoneLite->run(TopicExchangeConsumers::BLACK_ENDPOINT_ID, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup(failAtError: false, amountOfMessagesToHandle: 1));

        $this->assertSame(['some'], $handler->whiteReceived);
        $this->assertSame([], $handler->blackReceived);
    }

    public function test_a_fanout_exchange_broadcasts_to_every_bound_queue(): void
    {
        $handler = new FanoutExchangeConsumers();

        $ecotoneLite = $this->bootstrapFlowTesting(
            [FanoutExchangeConsumers::class],
            [
                $handler,
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::AMQP_PACKAGE])
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withExtensionObjects([
                    AmqpExchange::createFanoutExchange(self::FANOUT_EXCHANGE),
                    AmqpQueue::createWith(self::FANOUT_QUEUE_ONE),
                    AmqpQueue::createWith(self::FANOUT_QUEUE_TWO),
                    AmqpBinding::createFromNamesWithoutRoutingKey(self::FANOUT_EXCHANGE, self::FANOUT_QUEUE_ONE),
                    AmqpBinding::createFromNamesWithoutRoutingKey(self::FANOUT_EXCHANGE, self::FANOUT_QUEUE_TWO),
                    AmqpMessagePublisherConfiguration::create(exchangeName: self::FANOUT_EXCHANGE),
                ])
        );

        $ecotoneLite->getMessagePublisher()->send('some');

        $ecotoneLite->run(FanoutExchangeConsumers::QUEUE_ONE_ENDPOINT_ID, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup());
        $ecotoneLite->run(FanoutExchangeConsumers::QUEUE_TWO_ENDPOINT_ID, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup());

        $this->assertSame(['some'], $handler->queueOneReceived);
        $this->assertSame(['some'], $handler->queueTwoReceived);
    }
}

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class CustomExchangeConsumer
{
    public const ENDPOINT_ID = 'custom_exchange_endpoint';

    /** @var string[] */
    public array $received = [];

    #[RabbitConsumer(self::ENDPOINT_ID, AmqpChannelRoutingTest::CUSTOM_EXCHANGE_QUEUE)]
    public function handle(#[Payload] string $payload): void
    {
        $this->received[] = $payload;
    }
}

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class TopicExchangeConsumers
{
    public const WHITE_ENDPOINT_ID = 'topic_white_endpoint';
    public const BLACK_ENDPOINT_ID = 'topic_black_endpoint';

    /** @var string[] */
    public array $whiteReceived = [];
    /** @var string[] */
    public array $blackReceived = [];

    #[RabbitConsumer(self::WHITE_ENDPOINT_ID, AmqpChannelRoutingTest::TOPIC_WHITE_QUEUE)]
    public function handleWhite(#[Payload] string $payload): void
    {
        $this->whiteReceived[] = $payload;
    }

    #[RabbitConsumer(self::BLACK_ENDPOINT_ID, AmqpChannelRoutingTest::TOPIC_BLACK_QUEUE)]
    public function handleBlack(#[Payload] string $payload): void
    {
        $this->blackReceived[] = $payload;
    }
}

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class FanoutExchangeConsumers
{
    public const QUEUE_ONE_ENDPOINT_ID = 'fanout_queue_one_endpoint';
    public const QUEUE_TWO_ENDPOINT_ID = 'fanout_queue_two_endpoint';

    /** @var string[] */
    public array $queueOneReceived = [];
    /** @var string[] */
    public array $queueTwoReceived = [];

    #[RabbitConsumer(self::QUEUE_ONE_ENDPOINT_ID, AmqpChannelRoutingTest::FANOUT_QUEUE_ONE)]
    public function handleQueueOne(#[Payload] string $payload): void
    {
        $this->queueOneReceived[] = $payload;
    }

    #[RabbitConsumer(self::QUEUE_TWO_ENDPOINT_ID, AmqpChannelRoutingTest::FANOUT_QUEUE_TWO)]
    public function handleQueueTwo(#[Payload] string $payload): void
    {
        $this->queueTwoReceived[] = $payload;
    }
}
