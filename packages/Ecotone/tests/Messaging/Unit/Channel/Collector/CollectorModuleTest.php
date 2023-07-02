<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Channel\Collector;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\Collector\CollectedMessage;
use Ecotone\Messaging\Channel\Collector\Config\CollectorConfiguration;
use Ecotone\Messaging\Channel\ExceptionalQueueChannel;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Handler\MessageHandlingException;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\Config\BusModule;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Unit\Handler\Logger\LoggerExample;
use Test\Ecotone\Modelling\Fixture\Order\OrderService;
use Test\Ecotone\Modelling\Fixture\Order\OrderWasPlaced;
use Test\Ecotone\Modelling\Fixture\Order\PlaceOrder;
use Test\Ecotone\Modelling\Fixture\OrderAsynchronousEventHandler\GenericNotifier;
use Test\Ecotone\Modelling\Fixture\OrderAsynchronousEventHandler\PushStatistics;
use Test\Ecotone\Modelling\Fixture\OrderAsynchronousEventHandler\ShippingEventHandler;
use Test\Ecotone\Modelling\Fixture\OrderAsynchronousEventHandler\SmsNotifier;
use Test\Ecotone\Modelling\Fixture\OrderAsynchronousEventHandler\StatisticsHandler;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Test\Ecotone\Modelling\Fixture\PublishAndThrowException\FailureOrderService;

final class CollectorModuleTest extends TestCase
{
    public function test_receiving_collected_message_from_command_handler()
    {
        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class],
            [new OrderService()],
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                SimpleMessageChannelBuilder::createQueueChannel('push')
            ],
            [CollectorConfiguration::createWithOutboundChannel(['orders'], 'push')]
        );

        $ecotoneLite->sendCommand(new PlaceOrder('1'));

        $this->assertCount(0, $ecotoneLite->sendQueryWithRouting('order.getOrders'));

        /** @var CollectedMessage[] $collectedMessages */
        $collectedMessages = $ecotoneLite->getMessageChannel('push')->receive()->getPayload();

        $this->assertCount(1, $collectedMessages);
        $this->assertTrue($this->containsMessageFor($collectedMessages, 'orders', new PlaceOrder('1')));
    }

    public function test_receiving_collected_message_from_event_handler()
    {
        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class, GenericNotifier::class],
            [new OrderService(), new GenericNotifier()],
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                SimpleMessageChannelBuilder::createQueueChannel('notifications'),
                SimpleMessageChannelBuilder::createQueueChannel('push')
            ],
            [
                CollectorConfiguration::createWithOutboundChannel(['notifications'], 'push')
            ]
        );

        $ecotoneLite->sendCommandWithRoutingKey(
            'order.register',
            new PlaceOrder('1')
        );

        $this->assertNull($ecotoneLite->getMessageChannel('push')->receive(), "No message should be collected yet");

        $ecotoneLite->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        /** @var CollectedMessage[] $collectedMessages */
        $collectedMessages = $ecotoneLite->getMessageChannel('push')->receive()->getPayload();

        $this->assertCount(1, $collectedMessages);
        $this->assertTrue($this->containsMessageFor($collectedMessages, 'notifications', new OrderWasPlaced('1')));
    }

    public function test_receiving_multiple_collected_messages_in_one_batch()
    {
        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class, GenericNotifier::class, SmsNotifier::class],
            [new OrderService(), new GenericNotifier(), new SmsNotifier()],
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                SimpleMessageChannelBuilder::createQueueChannel('notifications'),
                SimpleMessageChannelBuilder::createQueueChannel('push')
            ],
            [
                CollectorConfiguration::createWithOutboundChannel(['notifications'], 'push')
            ]
        );

        $ecotoneLite->sendCommandWithRoutingKey(
            'order.register',
            new PlaceOrder('1')
        );
        $this->assertNull($ecotoneLite->getMessageChannel('push')->receive(), 'No message should be collected yet');

        $ecotoneLite->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        /** @var CollectedMessage[] $collectedMessages */
        $collectedMessages = $ecotoneLite->getMessageChannel('push')->receive()->getPayload();

        $this->assertCount(2, $collectedMessages);
        $this->assertTrue($this->containsMessageWithRoutingKeyFor($collectedMessages, 'notifications', new OrderWasPlaced('1'), 'orderNotifierOrderWasPlaced'));
        $this->assertTrue($this->containsMessageWithRoutingKeyFor($collectedMessages, 'notifications', new OrderWasPlaced('1'), 'smsNotifier.handle'));
    }

    public function test_receiving_collected_messages_from_different_channels_in_on_batch()
    {
        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class, GenericNotifier::class, ShippingEventHandler::class],
            [new OrderService(), new GenericNotifier(), new ShippingEventHandler()],
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                SimpleMessageChannelBuilder::createQueueChannel('notifications'),
                SimpleMessageChannelBuilder::createQueueChannel('shipping'),
                SimpleMessageChannelBuilder::createQueueChannel('push')
            ],
            [
                CollectorConfiguration::createWithOutboundChannel(['notifications', 'shipping'], 'push')
            ]
        );

        $ecotoneLite->sendCommandWithRoutingKey(
            'order.register',
            new PlaceOrder('1')
        );

        $ecotoneLite->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        /** @var CollectedMessage[] $collectedMessages */
        $collectedMessages = $ecotoneLite->getMessageChannel('push')->receive()->getPayload();

        $this->assertCount(2, $collectedMessages);
        $this->assertTrue($this->containsMessageFor($collectedMessages, 'notifications', new OrderWasPlaced('1')));
        $this->assertTrue($this->containsMessageFor($collectedMessages, 'shipping', new OrderWasPlaced('1')));
    }

    public function test_receiving_collected_messages_by_two_collectors()
    {
        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class, GenericNotifier::class, ShippingEventHandler::class],
            [new OrderService(), new GenericNotifier(), new ShippingEventHandler()],
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                SimpleMessageChannelBuilder::createQueueChannel('notifications'),
                SimpleMessageChannelBuilder::createQueueChannel('shipping'),
                SimpleMessageChannelBuilder::createQueueChannel('push_notifications'),
                SimpleMessageChannelBuilder::createQueueChannel('push_shipping'),
            ],
            [
                CollectorConfiguration::createWithOutboundChannel(['notifications'], 'push_notifications'),
                CollectorConfiguration::createWithOutboundChannel(['shipping'], 'push_shipping'),
            ]
        );

        $ecotoneLite->sendCommandWithRoutingKey(
            'order.register',
            new PlaceOrder('1')
        );

        $ecotoneLite->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        /** @var CollectedMessage[] $collectedMessages */
        $collectedMessages = $ecotoneLite->getMessageChannel('push_notifications')->receive()->getPayload();
        $this->assertCount(1, $collectedMessages);
        $this->assertTrue($this->containsMessageFor($collectedMessages, 'notifications', new OrderWasPlaced('1')));

        /** @var CollectedMessage[] $collectedMessages */
        $collectedMessages = $ecotoneLite->getMessageChannel('push_shipping')->receive()->getPayload();
        $this->assertCount(1, $collectedMessages);
        $this->assertTrue($this->containsMessageFor($collectedMessages, 'shipping', new OrderWasPlaced('1')));
    }

    public function test_when_command_bus_inside_command_bus_it_will_still_be_sent_in_batch()
    {
        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class, StatisticsHandler::class],
            [new OrderService(), new StatisticsHandler()],
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                SimpleMessageChannelBuilder::createQueueChannel('pushStatistics'),
                SimpleMessageChannelBuilder::createQueueChannel('push')
            ],
            [CollectorConfiguration::createWithOutboundChannel(['pushStatistics'], 'push')]
        );

        $ecotoneLite->sendCommand(new PlaceOrder('1'));

        $ecotoneLite->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        /** @var CollectedMessage[] $collectedMessages */
        $collectedMessages = $ecotoneLite->getMessageChannel('push')->receive()->getPayload();

        $this->assertCount(2, $collectedMessages);
    }

    public function test_throwing_exception_if_multiple_collector_registered_for_same_channel()
    {
        $this->expectException(ConfigurationException::class);

        $this->bootstrapEcotone(
            [OrderService::class],
            [new OrderService()],
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                SimpleMessageChannelBuilder::createQueueChannel('push1'),
                SimpleMessageChannelBuilder::createQueueChannel('push2'),
            ],
            [
                CollectorConfiguration::createWithOutboundChannel(['orders'], 'push1'),
                CollectorConfiguration::createWithOutboundChannel(['orders'], 'push2'),
            ]
        );
    }

    public function test_using_default_collector_proxy_for_messages()
    {
        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class],
            [new OrderService()],
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders')
            ],
            [CollectorConfiguration::createWithDefaultProxy(['orders'])]
        );

        $ecotoneLite->sendCommandWithRoutingKey('order.register', new PlaceOrder('1'));
        $this->assertCount(0, $ecotoneLite->sendQueryWithRouting('order.getOrders'));
        $this->assertCount(0, $ecotoneLite->sendQueryWithRouting('order.getNotifiedOrders'));
        $ecotoneLite->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        $this->assertCount(1, $ecotoneLite->sendQueryWithRouting('order.getOrders'));
        $this->assertCount(0, $ecotoneLite->sendQueryWithRouting('order.getNotifiedOrders'));
        $ecotoneLite->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        $this->assertCount(1, $ecotoneLite->sendQueryWithRouting('order.getOrders'));
        $this->assertCount(1, $ecotoneLite->sendQueryWithRouting('order.getNotifiedOrders'));
    }

    public function test_failure_while_sending_to_collect_use_retry_strategy()
    {
        $loggerExample = LoggerExample::create();
        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class],
            [new OrderService(), 'logger' => $loggerExample],
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                ExceptionalQueueChannel::createWithExceptionOnSend('push', 2)
            ],
            [CollectorConfiguration::createWithOutboundChannel(['orders'], 'push')]
        );

        $ecotoneLite->sendCommand(new PlaceOrder('1'));

        /** @var CollectedMessage[] $collectedMessages */
        $collectedMessages = $ecotoneLite->getMessageChannel('push')->receive()->getPayload();

        $this->assertCount(1, $loggerExample->getInfo());
        $this->assertCount(1, $loggerExample->getWarning());
        $this->assertCount(1, $collectedMessages);
        $this->assertTrue($this->containsMessageFor($collectedMessages, 'orders', new PlaceOrder('1')));
    }

    public function test_failure_while_sending_which_can_not_be_recovered_should_be_logged()
    {
        $loggerExample = LoggerExample::create();
        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class],
            [new OrderService(), "logger" => $loggerExample],
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                ExceptionalQueueChannel::createWithExceptionOnSend('push', 3)
            ],
            [CollectorConfiguration::createWithOutboundChannel(['orders'], 'push')]
        );

        $this->assertCount(0, $loggerExample->getCritical());

        $ecotoneLite->sendCommand(new PlaceOrder('1'));

        $this->assertCount(1, $loggerExample->getCritical());
        $messages = \json_decode($loggerExample->getCritical()[0]['context']['messages'], true);
        $this->assertArrayHasKey('payload', $messages[0]);
        $this->assertArrayHasKey('headers', $messages[0]);
        $this->assertEquals('orders', $messages[0]['targetChannel']);
        $this->assertNull($ecotoneLite->getMessageChannel('push')->receive());
    }

    public function test_failure_while_sending_which_can_not_be_recovered_goes_to_error_channel_if_defined()
    {
        $loggerExample = LoggerExample::create();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class],
            [new OrderService(), 'logger' => $loggerExample],
            ServiceConfiguration::createWithDefaults()
                ->withDefaultErrorChannel('customerErrorChannel')
                ->withExtensionObjects([CollectorConfiguration::createWithOutboundChannel(['orders'], 'push')]),
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                ExceptionalQueueChannel::createWithExceptionOnSend('push', 3),
                SimpleMessageChannelBuilder::createQueueChannel('customerErrorChannel')
            ]
        );

        $this->assertCount(0, $loggerExample->getCritical());

        $ecotoneLite->sendCommand(new PlaceOrder('1'));

        $this->assertCount(1, $loggerExample->getCritical());
        /** @var MessageHandlingException $payload */
        $payload = $ecotoneLite->getMessageChannel('customerErrorChannel')->receive()->getPayload();
        $this->assertEquals(
            new PlaceOrder('1'),
            $payload->getFailedMessage()->getPayload()
        );
    }


    public function test_failure_during_sending_should_not_affect_handling_original_command()
    {
        $ecotoneLite = $this->bootstrapEcotone(
            [OrderService::class, GenericNotifier::class],
            [new OrderService(), new GenericNotifier()],
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                SimpleMessageChannelBuilder::createQueueChannel('notifications'),
                ExceptionalQueueChannel::createWithExceptionOnSend('push', 3)
            ],
            [
                CollectorConfiguration::createWithOutboundChannel(['notifications'], 'push')
            ]
        );

        $ecotoneLite->sendCommandWithRoutingKey(
            'order.register',
            new PlaceOrder('1')
        );

        $ecotoneLite->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertNull($ecotoneLite->getMessageChannel('push')->receive());
        $this->assertNull($ecotoneLite->getMessageChannel('notifications')->receive());
        $this->assertCount(1, $ecotoneLite->sendQueryWithRouting('order.getOrders'));
    }

    public function test_collected_messages_will_be_discarded_in_case_of_error_in_message_handler()
    {
        $ecotoneLite = $this->bootstrapEcotone(
            [FailureOrderService::class],
            [new FailureOrderService()],
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                SimpleMessageChannelBuilder::createQueueChannel('push')
            ],
            [CollectorConfiguration::createWithOutboundChannel(['orders'], 'push')]
        );

        try {
            $ecotoneLite->sendCommand(new PlaceOrder('1'));
        }catch (\Exception $exception) {}

        /** @var CollectedMessage[] $collectedMessages */
        $this->assertNull($ecotoneLite->getMessageChannel('push')->receive());
    }

    /**
     * @param string[] $classesToResolve
     * @param object[] $services
     * @param MessageChannelBuilder[] $channelBuilders
     * @param CollectorConfiguration[] $collectorConfigurations
     */
    private function bootstrapEcotone(array $classesToResolve, array $services, array $channelBuilders, array $collectorConfigurations): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            $classesToResolve,
            $services,
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects($collectorConfigurations),
            enableAsynchronousProcessing: $channelBuilders
        );
    }

    /**
     * @param CollectedMessage[] $collectedMessages
     */
    private function containsMessageFor(array $collectedMessages, string $channelName, object $payload): bool
    {
        foreach ($collectedMessages as $collectedMessage) {
            if ($collectedMessage->getChannelName() === $channelName && $collectedMessage->getMessage()->getPayload() == $payload) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param CollectedMessage[] $collectedMessages
     */
    private function containsMessageWithRoutingKeyFor(array $collectedMessages, string $channelName, object $payload, string $routingKey): bool
    {
        foreach ($collectedMessages as $collectedMessage) {
            if ($collectedMessage->getChannelName() === $channelName && $collectedMessage->getMessage()->getPayload() == $payload && \str_contains($collectedMessage->getMessage()->getHeaders()->get(MessageHeaders::ROUTING_SLIP), $routingKey)) {
                return true;
            }
        }

        return false;
    }
}