<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Endpoint\Poller;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\ExceptionalQueueChannel;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\InMemoryChannelResolver;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\NullAcknowledgementCallback;
use Ecotone\Messaging\Endpoint\PollingConsumer\PollingConsumerBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagingException;
use Ecotone\Messaging\Support\MessageBuilder;
use InvalidArgumentException;
use RuntimeException;
use Test\Ecotone\Messaging\Fixture\Endpoint\ConsumerStoppingService;
use Test\Ecotone\Messaging\Fixture\Endpoint\ConsumerThrowingExceptionService;
use Test\Ecotone\Messaging\Fixture\Handler\DataReturningService;
use Test\Ecotone\Messaging\Fixture\Handler\SuccessServiceActivator;
use Test\Ecotone\Messaging\Unit\MessagingTest;

/**
 * Class PollingConsumerBuilderTest
 * @package Test\Ecotone\Messaging\Unit\Endpoint
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * @internal
 */
class PollingConsumerBuilderTest extends MessagingTest
{
    /**
     * @throws MessagingException
     */
    public function test_creating_consumer_with_default_period_trigger()
    {
        $pollingConsumerBuilder = new PollingConsumerBuilder(InterfaceToCallRegistry::createEmpty());
        $inputChannelName = 'inputChannelName';
        $inputChannel = QueueChannel::create();

        $directObjectReference = ConsumerStoppingService::create(null);
        $replyViaHeadersMessageHandlerBuilder = ServiceActivatorBuilder::createWithDirectReference($directObjectReference, 'executeNoReturn')
            ->withEndpointId('test')
            ->withInputChannelName($inputChannelName);
        $pollingConsumer = $pollingConsumerBuilder->build(
            InMemoryChannelResolver::createFromAssociativeArray([
                $inputChannelName => $inputChannel,
            ]),
            InMemoryReferenceSearchService::createEmpty(),
            $replyViaHeadersMessageHandlerBuilder,
            PollingMetadata::create('some')
        );

        $directObjectReference->setConsumerLifecycle($pollingConsumer);
        $inputChannel->send(MessageBuilder::withPayload('somePayload')->build());
        $pollingConsumer->run();

        $this->assertEquals(
            'somePayload',
            $directObjectReference->getReceivedPayload()
        );
    }

    /**
     * @throws MessagingException
     */
    public function test_passing_message_to_error_channel_on_failure()
    {
        $pollingConsumerBuilder = new PollingConsumerBuilder(InterfaceToCallRegistry::createEmpty());
        $inputChannelName = 'inputChannelName';
        $errorChannelName = 'errorChannel';
        $inputChannel = QueueChannel::create();
        $errorChannel = QueueChannel::create();

        $directObjectReference = ConsumerThrowingExceptionService::create();
        $replyViaHeadersMessageHandlerBuilder = ServiceActivatorBuilder::createWithDirectReference($directObjectReference, 'execute')
            ->withEndpointId('test')
            ->withInputChannelName($inputChannelName);

        $pollingConsumer = $pollingConsumerBuilder->build(
            InMemoryChannelResolver::createFromAssociativeArray([
                $inputChannelName => $inputChannel,
                $errorChannelName => $errorChannel,
            ]),
            InMemoryReferenceSearchService::createEmpty(),
            $replyViaHeadersMessageHandlerBuilder,
            PollingMetadata::create('some')
                ->setErrorChannelName($errorChannelName)
        );

        $directObjectReference->setConsumerLifecycle($pollingConsumer);
        $inputChannel->send(MessageBuilder::withPayload('somePayload')->build());

        $pollingConsumer->run();

        $this->assertNotNull($errorChannel->receive());
    }

    public function test_retrying_template_should_not_handle_exception_thrown_during_handling_of_message()
    {
        $pollingConsumerBuilder = new PollingConsumerBuilder(InterfaceToCallRegistry::createEmpty());
        $inputChannelName = 'inputChannelName';
        $inputChannel = QueueChannel::create();

        $directObjectReference = ConsumerThrowingExceptionService::create();
        $replyViaHeadersMessageHandlerBuilder = ServiceActivatorBuilder::createWithDirectReference($directObjectReference, 'execute')
            ->withEndpointId('test')
            ->withInputChannelName($inputChannelName);

        $pollingConsumer = $pollingConsumerBuilder->build(
            InMemoryChannelResolver::createFromAssociativeArray([
                $inputChannelName => $inputChannel,
            ]),
            InMemoryReferenceSearchService::createEmpty(),
            $replyViaHeadersMessageHandlerBuilder,
            PollingMetadata::create('some')
                ->setConnectionRetryTemplate(RetryTemplateBuilder::fixedBackOff(1)->maxRetryAttempts(1))
        );

        $inputChannel->send(MessageBuilder::withPayload('somePayload')->build());
        $inputChannel->send(MessageBuilder::withPayload('somePayload')->build());

        $exceptionThrown = false;
        try {
            $pollingConsumer->run();
        } catch (RuntimeException $e) {
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown);
        $this->assertEquals(1, $directObjectReference->getCalled());
    }

    public function test_retrying_template_should_handle_exceptions_thrown_before_handling_of_message()
    {
        $pollingConsumerBuilder = new PollingConsumerBuilder(InterfaceToCallRegistry::createEmpty());
        $inputChannelName = 'inputChannelName';
        $inputChannel = ExceptionalQueueChannel::createWithExceptionOnReceive();

        $serviceHandler = DataReturningService::createServiceActivatorBuilder('some')
            ->withEndpointId('test')
            ->withInputChannelName($inputChannelName);

        $pollingConsumer = $pollingConsumerBuilder->build(
            InMemoryChannelResolver::createFromAssociativeArray([
                $inputChannelName => $inputChannel,
            ]),
            InMemoryReferenceSearchService::createEmpty(),
            $serviceHandler,
            PollingMetadata::create('some')
                ->setConnectionRetryTemplate(RetryTemplateBuilder::fixedBackOff(1)->maxRetryAttempts(2))
        );

        try {
            $pollingConsumer->run();
        } catch (RuntimeException $e) {
        }

        $this->assertEquals(3, $inputChannel->getExceptionCount());
    }

    public function test_acking_message_when_ack_available_in_message_header()
    {
        $acknowledgementCallback = NullAcknowledgementCallback::create();
        $message = MessageBuilder::withPayload('some')
            ->setHeader(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION, 'amqpAcker')
            ->setHeader('amqpAcker', $acknowledgementCallback)
            ->build();
        $inputChannelName = 'inputChannel';
        $inputChannel = QueueChannel::create();
        $messageHandler = DataReturningService::createServiceActivatorBuilder('some')
                            ->withEndpointId('some-id')
                            ->withInputChannelName($inputChannelName);

        $pollingConsumer = $this->createPollingConsumer($inputChannelName, $inputChannel, $messageHandler);

        $inputChannel->send($message);

        $pollingConsumer->run();

        $this->assertTrue($acknowledgementCallback->isAcked());
    }

    public function test_requeing_message_on_gateway_failure()
    {
        $acknowledgementCallback = NullAcknowledgementCallback::create();
        $message = MessageBuilder::withPayload('some')
            ->setHeader(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION, 'amqpAcker')
            ->setHeader('amqpAcker', $acknowledgementCallback)
            ->build();

        $inputChannelName = 'inputChannel';
        $inputChannel = QueueChannel::create();
        $messageHandler = DataReturningService::createExceptionalServiceActivatorBuilder()
            ->withEndpointId('some-id')
            ->withInputChannelName($inputChannelName);

        $pollingConsumer = $this->createPollingConsumer($inputChannelName, $inputChannel, $messageHandler);

        $inputChannel->send($message);

        $pollingConsumer->run();

        $this->assertTrue($acknowledgementCallback->isRequeued());
    }

    public function test_throwing_exception_and_requeuing_when_stop_on_error_defined()
    {
        $acknowledgementCallback = NullAcknowledgementCallback::create();
        $message = MessageBuilder::withPayload('some')
            ->setHeader(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION, 'amqpAcker')
            ->setHeader('amqpAcker', $acknowledgementCallback)
            ->build();

        $inputChannelName = 'inputChannel';
        $inputChannel = QueueChannel::create();
        $messageHandler = DataReturningService::createExceptionalServiceActivatorBuilder()
            ->withEndpointId('some-id')
            ->withInputChannelName($inputChannelName);

        $pollingConsumer = $this->createPollingConsumer($inputChannelName, $inputChannel, $messageHandler);

        $inputChannel->send($message);

        $pollingConsumer->run();

        $this->assertTrue($acknowledgementCallback->isRequeued());
    }

    public function test_throwing_exception_and_rejecting_message()
    {
        $acknowledgementCallback = NullAcknowledgementCallback::create();
        $message = MessageBuilder::withPayload('some')
            ->setHeader(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION, 'amqpAcker')
            ->setHeader('amqpAcker', $acknowledgementCallback)
            ->build();

        $inputChannelName = 'inputChannel';
        $inputChannel = QueueChannel::create();
        $messageHandler = DataReturningService::createServiceActivatorBuilderWithRejectException()
            ->withEndpointId('some-id')
            ->withInputChannelName($inputChannelName);

        $pollingConsumer = $this->createPollingConsumer($inputChannelName, $inputChannel, $messageHandler);

        $inputChannel->send($message);

        $pollingConsumer->run();

        $this->assertTrue($acknowledgementCallback->isRejected());
        $this->assertFalse($acknowledgementCallback->isRequeued());
    }

    public function test_acking_message_with_fully_running_ecotone()
    {
        $messageChannelName = 'async_channel';
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [SuccessServiceActivator::class],
            [new SuccessServiceActivator()],
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel($messageChannelName),
            ]
        );

        $acknowledgeCallback = NullAcknowledgementCallback::create();
        $ecotoneTestSupport->sendDirectToChannel('handle_channel', metadata: [
            MessageHeaders::CONSUMER_ACK_HEADER_LOCATION => 'ack',
            'ack' => $acknowledgeCallback,
        ]);

        $this->assertFalse($acknowledgeCallback->isAcked());
        $ecotoneTestSupport->run($messageChannelName, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup());
        $this->assertTrue($acknowledgeCallback->isAcked());

        $acknowledgeCallback = NullAcknowledgementCallback::create();
        $ecotoneTestSupport->sendDirectToChannel('handle_channel', metadata: [
            MessageHeaders::CONSUMER_ACK_HEADER_LOCATION => 'ack',
            'ack' => $acknowledgeCallback,
        ]);

        $this->assertFalse($acknowledgeCallback->isAcked());
        $ecotoneTestSupport->run($messageChannelName, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup());
        $this->assertTrue($acknowledgeCallback->isAcked());
    }

    public function test_acking_on_gateway_failure_when_error_channel_defined()
    {
        $acknowledgementCallback = NullAcknowledgementCallback::create();
        $message = MessageBuilder::withPayload('some')
            ->setHeader(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION, 'amqpAcker')
            ->setHeader('amqpAcker', $acknowledgementCallback)
            ->build();

        $inputChannelName = 'inputChannel';
        $inputChannel = QueueChannel::create();
        $errorChannel = QueueChannel::create();
        $messageHandler = DataReturningService::createExceptionalServiceActivatorBuilder()
            ->withEndpointId('some-id')
            ->withInputChannelName($inputChannelName);

        $pollingConsumer = $this->createPollingConsumerWithCustomConfiguration(
            [
                $inputChannelName => $inputChannel,
                'errorChannel' => $errorChannel,
            ],
            $messageHandler,
            PollingMetadata::create('some')
                ->setExecutionAmountLimit(1)
                ->setErrorChannelName('errorChannel')
        );

        $inputChannel->send($message);

        $pollingConsumer->run();

        $this->assertNotNull($errorChannel);
        $this->assertTrue($acknowledgementCallback->isAcked());
    }

    public function test_throwing_exception_and_requeing_when_stop_on_failure_defined()
    {
        $acknowledgementCallback = NullAcknowledgementCallback::create();
        $message = MessageBuilder::withPayload('some')
            ->setHeader(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION, 'amqpAcker')
            ->setHeader('amqpAcker', $acknowledgementCallback)
            ->build();

        $inputChannelName = 'inputChannel';
        $inputChannel = QueueChannel::create();
        $messageHandler = DataReturningService::createExceptionalServiceActivatorBuilder()
            ->withEndpointId('some-id')
            ->withInputChannelName($inputChannelName);

        $pollingConsumer = $this->createPollingConsumerWithCustomConfiguration(
            [
                $inputChannelName => $inputChannel,
            ],
            $messageHandler,
            PollingMetadata::create('some')
                ->setStopOnError(true)
                ->setExecutionAmountLimit(1)
        );

        $this->expectException(InvalidArgumentException::class);

        $inputChannel->send($message);
        $pollingConsumer->run();

        $this->assertTrue($acknowledgementCallback->isRequeued());
    }

    public function test_throwing_exception_and_requeing_when_stop_on_failure_defined_and_error_channel()
    {
        $acknowledgementCallback = NullAcknowledgementCallback::create();
        $message = MessageBuilder::withPayload('some')
            ->setHeader(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION, 'amqpAcker')
            ->setHeader('amqpAcker', $acknowledgementCallback)
            ->build();

        $inputChannelName = 'inputChannel';
        $inputChannel = QueueChannel::create();
        $errorChannel = QueueChannel::create();
        $messageHandler = DataReturningService::createExceptionalServiceActivatorBuilder()
            ->withEndpointId('some-id')
            ->withInputChannelName($inputChannelName);

        $pollingConsumer = $this->createPollingConsumerWithCustomConfiguration(
            [
                $inputChannelName => $inputChannel,
                'errorChannel' => $errorChannel,
            ],
            $messageHandler,
            PollingMetadata::create('some')
                ->setStopOnError(true)
                ->setExecutionAmountLimit(1)
                ->setErrorChannelName('errorChannel')
        );

        $this->expectException(InvalidArgumentException::class);

        $inputChannel->send($message);
        $pollingConsumer->run();

        $this->assertTrue($acknowledgementCallback->isRequeued());
        $this->assertNull($errorChannel->receive());
    }

    public function test_finish_when_no_messages(): void
    {
        $inputChannelName = 'async_channel';
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [SuccessServiceActivator::class],
            [new SuccessServiceActivator()],
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel($inputChannelName)
            ]
        );

        $ecotoneTestSupport->sendDirectToChannel('handle_channel');

        $ecotoneTestSupport->run($inputChannelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(
            1,
            $ecotoneTestSupport->sendQueryWithRouting('get_number_of_calls')
        );
        $this->assertNull(
            $ecotoneTestSupport->getMessageChannel($inputChannelName)->receive()
        );
    }

    private function createPollingConsumer(string $inputChannelName, QueueChannel $inputChannel, $messageHandler): \Ecotone\Messaging\Endpoint\ConsumerLifecycle
    {
        return (new PollingConsumerBuilder(InterfaceToCallRegistry::createEmpty()))->build(
            InMemoryChannelResolver::createFromAssociativeArray([
                $inputChannelName => $inputChannel,
            ]),
            InMemoryReferenceSearchService::createEmpty(),
            $messageHandler,
            PollingMetadata::create('some')
                ->setExecutionAmountLimit(1)
        );
    }

    private function createPollingConsumerWithCustomConfiguration(array $channels, $messageHandler, PollingMetadata  $pollingMetadata): \Ecotone\Messaging\Endpoint\ConsumerLifecycle
    {
        return (new PollingConsumerBuilder(InterfaceToCallRegistry::createEmpty()))->build(
            InMemoryChannelResolver::createFromAssociativeArray($channels),
            InMemoryReferenceSearchService::createEmpty(),
            $messageHandler,
            $pollingMetadata
        );
    }
}
