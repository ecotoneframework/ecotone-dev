<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Endpoint;

use Ecotone\Api\PollingMetadata;
use Ecotone\Api\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Endpoint\InboundChannelAdapter\InboundChannelAdapterBuilder;
use Ecotone\Messaging\Endpoint\NullAcknowledgementCallback;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Messaging\Transaction\Null\NullTransaction;
use Ecotone\Messaging\Transaction\Null\NullTransactionFactory;
use Ecotone\Messaging\Transaction\Transactional;
use Ecotone\Messaging\Transaction\TransactionInterceptor;
use Ecotone\Test\ComponentTestBuilder;
use Test\Ecotone\Messaging\Fixture\Endpoint\ConsumerContinuouslyWorkingService;
use Test\Ecotone\Messaging\Fixture\Endpoint\ConsumerStoppingService;
use Test\Ecotone\Messaging\Unit\MessagingTestCase;

/**
 * Class InboundChannelAdapterBuilderTest
 * @package Test\Ecotone\Messaging\Unit\Endpoint
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 *
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class InboundChannelAdapterBuilderTest extends MessagingTestCase
{
    /**
     * @throws InvalidArgumentException
     * @throws \Ecotone\Messaging\MessagingException
     */
    public function test_running_with_interceptor_from_interface_method_annotation()
    {
        $payload = 'testPayload';
        $requestChannelName = 'requestChannelName';
        $requestChannel = QueueChannel::create();
        $inboundChannelAdapterStoppingService = ConsumerContinuouslyWorkingService::createWithReturn($payload);

        $transactionOne = NullTransaction::start();
        $transactionFactoryOne = NullTransactionFactory::createWithPredefinedTransaction($transactionOne);

        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::create($requestChannelName, $requestChannel))
            ->withReference('someRef', $inboundChannelAdapterStoppingService)
            ->withReference('transactionFactory2', $transactionFactoryOne)
            ->withPollingMetadata(PollingMetadata::create('test')->setHandledMessageLimit(1))
            ->withInboundChannelAdapter(
                InboundChannelAdapterBuilder::create(
                    $requestChannelName,
                    'someRef',
                    InterfaceToCall::create($inboundChannelAdapterStoppingService::class, 'executeReturnWithInterceptor')
                )
                ->withEndpointId('test')
            )
            ->withAroundInterceptor(AroundInterceptorBuilder::createWithDirectObjectAndResolveConverters(InterfaceToCallRegistry::createEmpty(), new TransactionInterceptor(), 'transactional', 1, $inboundChannelAdapterStoppingService::class))
            ->build();

        $messaging->run('test');

        $this->assertTrue($transactionOne->isCommitted());
    }

    /**
     * @throws InvalidArgumentException
     * @throws \Ecotone\Messaging\MessagingException
     */
    public function test_running_with_interceptor_from_interface_class_annotation()
    {
        $payload = 'testPayload';
        $requestChannelName = 'requestChannelName';
        $requestChannel = QueueChannel::create();
        $inboundChannelAdapterStoppingService = ConsumerContinuouslyWorkingService::createWithReturn($payload);

        $transactionOne = NullTransaction::start();
        $transactionFactoryOne = NullTransactionFactory::createWithPredefinedTransaction($transactionOne);

        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::create($requestChannelName, $requestChannel))
            ->withReference('someRef', $inboundChannelAdapterStoppingService)
            ->withReference('transactionFactory1', $transactionFactoryOne)
            ->withPollingMetadata(PollingMetadata::create('test')->setHandledMessageLimit(1))
            ->withInboundChannelAdapter(
                InboundChannelAdapterBuilder::create(
                    $requestChannelName,
                    'someRef',
                    InterfaceToCall::create($inboundChannelAdapterStoppingService::class, 'executeReturn')
                )
                    ->withEndpointId('test')
            )
            ->withAroundInterceptor(AroundInterceptorBuilder::createWithDirectObjectAndResolveConverters(InterfaceToCallRegistry::createEmpty(), new TransactionInterceptor(), 'transactional', 1, $inboundChannelAdapterStoppingService::class))
            ->build();

        $messaging->run('test');

        $this->assertTrue($transactionOne->isCommitted());
    }

    /**
     * @throws InvalidArgumentException
     * @throws \Ecotone\Messaging\MessagingException
     */
    public function test_running_with_interceptor_from_endpoint_annotation()
    {
        $payload = 'testPayload';
        $requestChannelName = 'requestChannelName';
        $requestChannel = QueueChannel::create();
        $inboundChannelAdapterStoppingService = ConsumerContinuouslyWorkingService::createWithReturn($payload);

        $transactionOne = NullTransaction::start();
        $transactionFactoryOne = NullTransactionFactory::createWithPredefinedTransaction($transactionOne);

        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::create($requestChannelName, $requestChannel))
            ->withReference('someRef', $inboundChannelAdapterStoppingService)
            ->withReference('transactionFactory0', $transactionFactoryOne)
            ->withPollingMetadata(PollingMetadata::create('test')->setHandledMessageLimit(1))
            ->withInboundChannelAdapter(
                InboundChannelAdapterBuilder::create(
                    $requestChannelName,
                    'someRef',
                    InterfaceToCall::create($inboundChannelAdapterStoppingService::class, 'executeReturnWithInterceptor')
                )
                ->withEndpointId('test')
                ->withEndpointAnnotations([new AttributeDefinition(Transactional::class, [['transactionFactory0']])])
            )
            ->withAroundInterceptor(AroundInterceptorBuilder::createWithDirectObjectAndResolveConverters(InterfaceToCallRegistry::createEmpty(), new TransactionInterceptor(), 'transactional', 1, $inboundChannelAdapterStoppingService::class))
            ->build();

        $messaging->run('test');

        $this->assertTrue($transactionOne->isCommitted());
    }

    /**
     * @throws InvalidArgumentException
     * @throws \Ecotone\Messaging\MessagingException
     */
    public function test_running_with_memory_limit()
    {
        $payload = 'testPayload';
        $requestChannelName = 'requestChannelName';
        $requestChannel = QueueChannel::create();
        $inboundChannelAdapterStoppingService = ConsumerContinuouslyWorkingService::createWithReturn($payload);

        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::create($requestChannelName, $requestChannel))
            ->withReference('someRef', $inboundChannelAdapterStoppingService)
            ->withPollingMetadata(PollingMetadata::create('test')->setMemoryLimitInMegaBytes(1))
            ->withInboundChannelAdapter(
                InboundChannelAdapterBuilder::create(
                    $requestChannelName,
                    'someRef',
                    InterfaceToCall::create($inboundChannelAdapterStoppingService::class, 'executeReturn')
                )
                ->withEndpointId('test')
            )
            ->build();

        $messaging->run('test');

        $this->assertNull($requestChannel->receive());
    }

    public function test_acking_message_when_ack_available_in_message_header_in_inbound_channel_adapter()
    {
        // write for real implementation tests, if is acked correctly

        $acknowledgementCallback = NullAcknowledgementCallback::create();
        $message = MessageBuilder::withPayload('some')
            ->setHeader(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION, 'amqpAcker')
            ->setHeader('amqpAcker', $acknowledgementCallback)
            ->build();
        $inputChannelName = 'inputChannelName';
        $inputChannel = QueueChannel::create();
        $inboundChannelAdapterStoppingService = ConsumerStoppingService::create($message);

        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::create($inputChannelName, $inputChannel))
            ->withReference('someRef', $inboundChannelAdapterStoppingService)
            ->withPollingMetadata(
                PollingMetadata::create('test')
                    ->setFixedRateInMilliseconds(1)
                    ->setInitialDelayInMilliseconds(0)
                    ->setHandledMessageLimit(1)
                    ->setExecutionAmountLimit(1)
            )
            ->withInboundChannelAdapter(
                InboundChannelAdapterBuilder::create(
                    $inputChannelName,
                    'someRef',
                    InterfaceToCall::create($inboundChannelAdapterStoppingService::class, 'execute')
                )
                ->withEndpointId('test')
            )
            ->build();

        $messaging->run('test');

        $this->assertTrue($acknowledgementCallback->isAcked());
    }

    /**
     * @throws InvalidArgumentException
     * @throws \Ecotone\Messaging\MessagingException
     */
    public function test_throwing_exception_if_reference_service_has_no_passed_method()
    {
        $payload = 'testPayload';
        $inputChannelName = 'inputChannelName';
        $inputChannel = QueueChannel::create();
        $inboundChannelAdapterStoppingService = ConsumerStoppingService::create($payload);

        $this->expectException(InvalidArgumentException::class);

        ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::create($inputChannelName, $inputChannel))
            ->withReference('someRef', $inboundChannelAdapterStoppingService)
            ->withPollingMetadata(PollingMetadata::create('test'))
            ->withInboundChannelAdapter(
                InboundChannelAdapterBuilder::create(
                    $inputChannelName,
                    'someRef',
                    InterfaceToCall::create($inboundChannelAdapterStoppingService::class, 'notExistingMethod')
                )
            )
            ->build();
    }
}
