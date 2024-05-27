<?php

namespace Test\Ecotone\Messaging\Unit\Handler\Gateway;

use Ecotone\Messaging\Channel\DirectChannel;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\MethodInterceptor\BeforeSendGateway;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Conversion\ArrayToJson\ArrayToJsonConverter;
use Ecotone\Messaging\Conversion\AutoCollectionConversionService;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\JsonToArray\JsonToArrayConverter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Conversion\StringToUuid\StringToUuidConverter;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeadersBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderValueBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlingException;
use Ecotone\Messaging\Handler\NonProxyGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptor;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagingException;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\ErrorMessage;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Messaging\Transaction\Null\NullTransaction;
use Ecotone\Messaging\Transaction\Null\NullTransactionFactory;
use Ecotone\Messaging\Transaction\Transactional;
use Ecotone\Messaging\Transaction\TransactionInterceptor;
use Ecotone\Test\ComponentTestBuilder;
use Ecotone\Test\InMemoryConversionService;
use Exception;
use ProxyManager\Proxy\RemoteObjectInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use stdClass;
use Test\Ecotone\Messaging\Fixture\Annotation\Converter\ExampleStdClassConverter;
use Test\Ecotone\Messaging\Fixture\Annotation\Converter\ExceptionalConverter;
use Test\Ecotone\Messaging\Fixture\Annotation\Interceptor\CalculatingServiceInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Channel\PollingChannelThrowingException;
use Test\Ecotone\Messaging\Fixture\Handler\DataReturningService;
use Test\Ecotone\Messaging\Fixture\Handler\ExceptionMessageHandler;
use Test\Ecotone\Messaging\Fixture\Handler\Gateway\IteratorReturningGateway;
use Test\Ecotone\Messaging\Fixture\Handler\Gateway\MessageReturningGateway;
use Test\Ecotone\Messaging\Fixture\Handler\Gateway\MixedReturningGateway;
use Test\Ecotone\Messaging\Fixture\Handler\Gateway\StringReturningGateway;
use Test\Ecotone\Messaging\Fixture\Handler\Gateway\UuidReturningGateway;
use Test\Ecotone\Messaging\Fixture\Handler\NoReturnMessageHandler;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\TransactionalInterceptorOnGatewayClassAndMethodExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\TransactionalInterceptorOnGatewayClassExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\TransactionalInterceptorOnGatewayMethodExample;
use Test\Ecotone\Messaging\Fixture\Handler\ReplyViaHeadersMessageHandler;
use Test\Ecotone\Messaging\Fixture\Handler\StatefulHandler;
use Test\Ecotone\Messaging\Fixture\MessageConverter\FakeMessageConverter;
use Test\Ecotone\Messaging\Fixture\MessageConverter\FakeMessageConverterGatewayExample;
use Test\Ecotone\Messaging\Fixture\Service\CalculatingService;
use Test\Ecotone\Messaging\Fixture\Service\ServiceExpectingNoArguments;
use Test\Ecotone\Messaging\Fixture\Service\ServiceExpectingOneArgument;
use Test\Ecotone\Messaging\Fixture\Service\ServiceInterface\ServiceInterfaceCalculatingService;
use Test\Ecotone\Messaging\Fixture\Service\ServiceInterface\ServiceInterfaceReceiveOnly;
use Test\Ecotone\Messaging\Fixture\Service\ServiceInterface\ServiceInterfaceReceiveOnlyWithNull;
use Test\Ecotone\Messaging\Fixture\Service\ServiceInterface\ServiceInterfaceSendAndReceive;
use Test\Ecotone\Messaging\Fixture\Service\ServiceInterface\ServiceInterfaceSendOnly;
use Test\Ecotone\Messaging\Fixture\Service\ServiceInterface\ServiceInterfaceSendOnlyWithTwoArguments;
use Test\Ecotone\Messaging\Fixture\Service\ServiceInterface\ServiceInterfaceWithFutureReceive;
use Test\Ecotone\Messaging\Fixture\Service\ServiceInterface\ServiceReceivingMessageAndReturningMessage;
use Test\Ecotone\Messaging\Fixture\Service\ServiceInterface\ServiceWithMixed;
use Test\Ecotone\Messaging\Unit\MessagingTest;

/**
 * Class GatewayProxyBuilderTest
 * @package Ecotone\Messaging\Config
 * @author  Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * @internal
 */
class GatewayProxyBuilderTest extends MessagingTest
{
    public function test_creating_gateway_for_send_only_interface()
    {
        $messageHandler = NoReturnMessageHandler::create();
        $messaging = ComponentTestBuilder::create()
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceWithMixed::class,
                    ServiceWithMixed::class,
                    'sendWithoutReturnValue',
                    $inputChannel = 'inputChannel'
                )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference($messageHandler, 'handle')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $messaging->getGateway(ServiceWithMixed::class)
            ->sendWithoutReturnValue(new stdClass());

        $this->assertTrue($messageHandler->wasCalled());
    }

    public function test_throwing_exception_if_reply_channel_passed_for_send_only_interface()
    {
        $this->expectException(InvalidArgumentException::class);

        ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::createQueueChannel('replyChannel'))
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceWithMixed::class,
                    ServiceWithMixed::class,
                    'sendWithoutReturnValue',
                    $inputChannel = 'inputChannel'
                )->withReplyChannel('replyChannel')
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ServiceExpectingOneArgument::create(), 'withMessage')
                    ->withInputChannelName($inputChannel)
            )
            ->build();
    }

    public function test_creating_gateway_for_receive_only()
    {
        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::createQueueChannel('replyChannel'))
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceReceiveOnly::class,
                    ServiceInterfaceReceiveOnly::class,
                    'sendMail',
                    $inputChannel = 'inputChannel'
                )->withReplyChannel('replyChannel')
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ServiceExpectingNoArguments::createWithReturnValue($result = 'test'), 'withReturnValue')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $this->assertEquals(
            $result,
            $messaging->getGateway(ServiceInterfaceReceiveOnly::class)->sendMail()
        );
    }

    public function test_calling_reply_queue_with_time_out()
    {
        $payload = 'replyData';
        $replyMessage = MessageBuilder::withPayload($payload)->build();
        $replyChannel = new class ($replyMessage) extends QueueChannel {
            public function __construct(private Message $replyMessage)
            {
                parent::__construct('');
            }
            public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
            {
                if ($timeoutInMilliseconds === 1) {
                    return $this->replyMessage;
                }
                throw InvalidArgumentException::create("Timeout should be 1, but got {$timeoutInMilliseconds}");
            }
        };

        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::create('replyChannel', $replyChannel))
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceReceiveOnly::class,
                    ServiceInterfaceReceiveOnly::class,
                    'sendMail',
                    $inputChannel = 'inputChannel'
                )
                    ->withReplyMillisecondTimeout(1)
                    ->withReplyChannel('replyChannel')
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(NoReturnMessageHandler::create(), 'handle')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $this->assertEquals(
            $payload,
            $messaging->getGateway(ServiceInterfaceReceiveOnly::class)->sendMail()
        );
    }

    public function test_ignoring_reply_from_message_handler_when_reply_channel_is_set()
    {
        $payload = 'replyData';
        $replyMessage = MessageBuilder::withPayload($payload)->build();
        $replyChannel = new class ($replyMessage) extends QueueChannel {
            public function __construct(private Message $replyMessage)
            {
                parent::__construct('');
            }
            public function receive(): ?Message
            {
                return $this->replyMessage;
            }
        };

        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::create('replyChannel', $replyChannel))
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceReceiveOnly::class,
                    ServiceInterfaceReceiveOnly::class,
                    'sendMail',
                    $inputChannel = 'inputChannel'
                )
                    ->withReplyChannel('replyChannel')
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ServiceExpectingNoArguments::createWithReturnValue('test'), 'withReturnValue')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $this->assertEquals(
            $payload,
            $messaging->getGateway(ServiceInterfaceReceiveOnly::class)->sendMail()
        );
    }

    public function test_executing_with_method_argument_converters()
    {
        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::createQueueChannel($outputChannel = 'outputChannel'))
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceSendOnlyWithTwoArguments::class,
                    ServiceInterfaceSendOnlyWithTwoArguments::class,
                    'sendMail',
                    $inputChannel = 'inputChannel'
                )
                    ->withParameterConverters(
                        [
                            GatewayHeaderBuilder::create('personId', 'personId'),
                            GatewayPayloadBuilder::create('content'),
                        ]
                    )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ServiceExpectingOneArgument::create(), 'withMessage')
                    ->withInputChannelName($inputChannel)
                    ->withOutputMessageChannel($outputChannel)
            )
            ->build();

        $messaging->getGateway(ServiceInterfaceSendOnlyWithTwoArguments::class)
                        ->sendMail($personId = '123', $content = 'some bla content');

        $message = $messaging->receiveMessageFrom($outputChannel);

        $this->assertEquals($personId, $message->getHeaders()->get('personId'));
        $this->assertEquals($content, $message->getPayload());
    }

    public function test_throwing_exception_if_two_payload_converters_passed()
    {
        $this->expectException(InvalidArgumentException::class);

        ComponentTestBuilder::create()
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceSendOnlyWithTwoArguments::class,
                    ServiceInterfaceSendOnlyWithTwoArguments::class,
                    'sendMail',
                    $inputChannel = 'inputChannel'
                )
                    ->withParameterConverters(
                        [
                            GatewayPayloadBuilder::create('content'),
                            GatewayPayloadBuilder::create('personId'),
                        ]
                    )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ServiceExpectingOneArgument::create(), 'withMessage')
                    ->withInputChannelName($inputChannel)
            )
            ->build();
    }

    public function test_converters_execution_according_to_order_in_list()
    {
        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::createQueueChannel($outputChannel = 'outputChannel'))
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceSendOnly::class,
                    ServiceInterfaceSendOnly::class,
                    'sendMailWithMetadata',
                    $inputChannel = 'inputChannel'
                )
                    ->withParameterConverters(
                        [
                            GatewayHeadersBuilder::create('metadata'),
                            GatewayHeaderBuilder::create('content', 'personId'),
                        ]
                    )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ServiceExpectingOneArgument::create(), 'withMessage')
                    ->withInputChannelName($inputChannel)
                    ->withOutputMessageChannel($outputChannel)
            )
            ->build();

        $messaging->getGateway(ServiceInterfaceSendOnly::class)
            ->sendMailWithMetadata(3, ['personId' => 2]);

        $message = $messaging->receiveMessageFrom($outputChannel);

        $this->assertEquals(3, $message->getHeaders()->get('personId'));
    }

    public function test_executing_with_multiple_message_converters_for_same_parameter()
    {
        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::createQueueChannel($outputChannel = 'outputChannel'))
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceSendOnly::class,
                    ServiceInterfaceSendOnly::class,
                    'sendMail',
                    $inputChannel = 'inputChannel'
                )
                    ->withParameterConverters(
                        [
                            GatewayHeaderBuilder::create('content', 'test1'),
                            GatewayHeaderBuilder::create('content', 'test2'),
                        ]
                    )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ServiceExpectingOneArgument::create(), 'withMessage')
                    ->withInputChannelName($inputChannel)
                    ->withOutputMessageChannel($outputChannel)
            )
            ->build();

        $messaging->getGateway(ServiceInterfaceSendOnly::class)
            ->sendMail($content = 'testContent');

        $message = $messaging->receiveMessageFrom($outputChannel);
        $this->assertEquals($content, $message->getHeaders()->get('test1'));
        $this->assertEquals($content, $message->getHeaders()->get('test2'));
    }

    /**
     * @throws InvalidArgumentException
     * @throws MessagingException
     */
    public function test_throwing_exception_if_gateway_expect_reply_and_request_channel_is_queue()
    {
        $this->expectException(InvalidArgumentException::class);

        ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::createQueueChannel($inputChannel = 'inputChannel'))
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceReceiveOnly::class,
                    ServiceInterfaceReceiveOnly::class,
                    'sendMail',
                    $inputChannel
                )
            )
            ->build();
    }

    /**
     * @throws InvalidArgumentException
     * @throws MessagingException
     */
    public function test_creating_with_queue_channel_when_gateway_does_not_expect_reply()
    {
        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::createQueueChannel($inputChannel = 'inputChannel'))
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceSendOnly::class,
                    ServiceInterfaceSendOnly::class,
                    'sendMail',
                    $inputChannel
                )
            )
            ->build();

        $messaging->getGateway(ServiceInterfaceSendOnly::class)
            ->sendMail('some');

        $this->assertEquals(
            'some',
            $messaging->receiveMessageFrom($inputChannel)->getPayload()
        );
    }

    public function test_resolving_response_in_future_from_handler()
    {
        $messaging = ComponentTestBuilder::create()
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceWithFutureReceive::class,
                    ServiceInterfaceWithFutureReceive::class,
                    'someLongRunningWork',
                    $inputChannel = 'inputChannel'
                )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ServiceExpectingOneArgument::create(), 'withMessage')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $this->assertNotNull(
            $messaging->getGateway(ServiceInterfaceWithFutureReceive::class)
                ->someLongRunningWork()
                ->resolve()
        );
    }

    public function test_resolving_response_in_future_from_reply_channel(): void
    {
        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::createQueueChannel($replyChannelName = 'replyChannel'))
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceWithFutureReceive::class,
                    ServiceInterfaceWithFutureReceive::class,
                    'someLongRunningWork',
                    $inputChannel = 'inputChannel'
                )
                    ->withReplyChannel($replyChannelName)
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ServiceExpectingOneArgument::create(), 'withMessage')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $messaging->getMessageChannel($replyChannelName)->send(MessageBuilder::withPayload('some')->build());

        $this->assertEquals(
            'some',
            $messaging->getGateway(ServiceInterfaceWithFutureReceive::class)
                ->someLongRunningWork()
                ->resolve()
        );
    }

    public function test_throwing_exception_when_received_error_message()
    {
        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::createQueueChannel($replyChannelName = 'replyChannel'))
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceReceiveOnly::class,
                    ServiceInterfaceReceiveOnly::class,
                    'sendMail',
                    $inputChannel = 'inputChannel'
                )
                    ->withReplyChannel($replyChannelName)
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ServiceExpectingOneArgument::create(), 'withMessage')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $messaging->getMessageChannel($replyChannelName)->send(ErrorMessage::create(MessageHandlingException::create('error occurred')));

        $this->expectException(MessageHandlingException::class);

        $messaging->getGateway(ServiceInterfaceReceiveOnly::class)
            ->sendMail();
    }

    public function test_throwing_exception_when_received_error_message_for_future_reply_sender()
    {
        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::createQueueChannel($replyChannelName = 'replyChannel'))
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceWithFutureReceive::class,
                    ServiceInterfaceWithFutureReceive::class,
                    'someLongRunningWork',
                    $inputChannel = 'inputChannel'
                )
                    ->withReplyChannel($replyChannelName)
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ServiceExpectingOneArgument::create(), 'withMessage')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $messaging->getMessageChannel($replyChannelName)->send(ErrorMessage::create(MessageHandlingException::create('error occurred')));

        $this->expectException(MessageHandlingException::class);

        $messaging->getGateway(ServiceInterfaceWithFutureReceive::class)
            ->someLongRunningWork()
            ->resolve();
    }

    public function test_returning_null_when_no_reply_received_for_nullable_interface()
    {
        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::createQueueChannel('replyChannel'))
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceReceiveOnlyWithNull::class,
                    ServiceInterfaceReceiveOnlyWithNull::class,
                    'sendMail',
                    $inputChannel = 'inputChannel'
                )->withReplyChannel('replyChannel')
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(NoReturnMessageHandler::create(), 'handle')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $this->assertNull(
            $messaging->getGateway(ServiceInterfaceReceiveOnlyWithNull::class)->sendMail()
        );
    }

    public function test_throwing_exception_when_reply_is_null_but_interface_expect_value()
    {
        $messaging = ComponentTestBuilder::create()
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceReceiveOnly::class,
                    ServiceInterfaceReceiveOnly::class,
                    'sendMail',
                    $inputChannel = 'inputChannel'
                )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(NoReturnMessageHandler::create(), 'handle')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $this->expectException(InvalidArgumentException::class);

        $messaging->getGateway(ServiceInterfaceReceiveOnly::class)->sendMail();
    }

    public function test_propagating_error_to_error_channel()
    {
        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::createQueueChannel($errorChannelName = 'error'))
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceReceiveOnlyWithNull::class,
                    ServiceInterfaceReceiveOnlyWithNull::class,
                    'sendMail',
                    $inputChannel = 'inputChannel'
                )
                ->withErrorChannel($errorChannelName)
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ExceptionMessageHandler::create(), 'handle')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $messaging->getGateway(ServiceInterfaceReceiveOnlyWithNull::class)->sendMail();

        $this->assertInstanceOf(
            ErrorMessage::class,
            $messaging->receiveMessageFrom($errorChannelName)
        );
    }

    public function test_propagating_error_to_error_channel_when_exception_happen_during_receiving_reply()
    {
        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::createQueueChannel($errorChannelName = 'error'))
            ->withChannel(SimpleMessageChannelBuilder::createQueueChannel($replyChannelName = 'replyChannel'))
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceReceiveOnlyWithNull::class,
                    ServiceInterfaceReceiveOnlyWithNull::class,
                    'sendMail',
                    $inputChannel = 'inputChannel'
                )
                    ->withReplyChannel($replyChannelName)
                    ->withErrorChannel($errorChannelName)
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(NoReturnMessageHandler::create(), 'handle')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $messaging->getMessageChannel($replyChannelName)->send(ErrorMessage::create(MessageHandlingException::create('error occurred')));

        $messaging->getGateway(ServiceInterfaceReceiveOnlyWithNull::class)->sendMail();

        $this->assertInstanceOf(
            ErrorMessage::class,
            $messaging->receiveMessageFrom($errorChannelName)
        );
    }

    public function test_throwing_root_cause_exception_when_no_error_channel_defined()
    {
        $messaging = ComponentTestBuilder::create()
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceReceiveOnlyWithNull::class,
                    ServiceInterfaceReceiveOnlyWithNull::class,
                    'sendMail',
                    $inputChannel = 'inputChannel'
                )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ExceptionMessageHandler::create(), 'handle')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $this->expectException(\InvalidArgumentException::class);

        $messaging->getGateway(ServiceInterfaceReceiveOnlyWithNull::class)->sendMail();
    }

    public function test_calling_interface_with_around_interceptor_from_endpoint_annotation()
    {
        $transactionOne = NullTransaction::start();
        $transactionInterceptor = new TransactionInterceptor();
        $transactionFactoryOne = NullTransactionFactory::createWithPredefinedTransaction($transactionOne);
        $messaging = ComponentTestBuilder::create()
            ->withReference('transactionFactory', $transactionFactoryOne)
            ->withReference('transactionInterceptor', $transactionInterceptor)
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceSendOnly::class,
                    ServiceInterfaceSendOnly::class,
                    'sendMail',
                    $inputChannel = 'inputChannel'
                )
                    ->withEndpointAnnotations([new AttributeDefinition(Transactional::class, [['transactionFactory']])])
                    ->addAroundInterceptor(
                        AroundInterceptorBuilder::create('transactionInterceptor', InterfaceToCall::create(TransactionInterceptor::class, 'transactional'), 1, Transactional::class, [])
                    )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(NoReturnMessageHandler::create(), 'handle')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $messaging->getGateway(ServiceInterfaceSendOnly::class)->sendMail('test');

        $this->assertTrue($transactionOne->isCommitted());
    }

    public function test_calling_interface_with_around_interceptor_from_method_annotation()
    {
        $transactionOne = NullTransaction::start();
        $transactionInterceptor = new TransactionInterceptor();
        $transactionFactoryOne = NullTransactionFactory::createWithPredefinedTransaction($transactionOne);
        $messaging = ComponentTestBuilder::create()
            ->withReference('transactionFactory', $transactionFactoryOne)
            ->withReference('transactionInterceptor', $transactionInterceptor)
            ->withGateway(
                GatewayProxyBuilder::create(
                    TransactionalInterceptorOnGatewayMethodExample::class,
                    TransactionalInterceptorOnGatewayMethodExample::class,
                    'invoke',
                    $inputChannel = 'inputChannel'
                )
                    ->addAroundInterceptor(
                        AroundInterceptorBuilder::createWithDirectObjectAndResolveConverters(InterfaceToCallRegistry::createEmpty(), $transactionInterceptor, 'transactional', 1, Transactional::class)
                    )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(NoReturnMessageHandler::create(), 'handle')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $messaging->getGateway(TransactionalInterceptorOnGatewayMethodExample::class)->invoke();

        $this->assertTrue($transactionOne->isCommitted());
    }

    public function test_calling_interface_with_around_interceptor_from_class_annotation()
    {
        $transactionOne = NullTransaction::start();
        $transactionInterceptor = new TransactionInterceptor();
        $transactionFactoryOne = NullTransactionFactory::createWithPredefinedTransaction($transactionOne);
        $messaging = ComponentTestBuilder::create()
            ->withReference('transactionFactory', $transactionFactoryOne)
            ->withReference('transactionInterceptor', $transactionInterceptor)
            ->withGateway(
                GatewayProxyBuilder::create(
                    TransactionalInterceptorOnGatewayClassExample::class,
                    TransactionalInterceptorOnGatewayClassExample::class,
                    'invoke',
                    $inputChannel = 'inputChannel'
                )
                    ->addAroundInterceptor(
                        AroundInterceptorBuilder::createWithDirectObjectAndResolveConverters(InterfaceToCallRegistry::createEmpty(), $transactionInterceptor, 'transactional', 1, Transactional::class)
                    )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(NoReturnMessageHandler::create(), 'handle')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $messaging->getGateway(TransactionalInterceptorOnGatewayClassExample::class)->invoke();

        $this->assertTrue($transactionOne->isCommitted());
    }

    public function test_calling_interface_with_around_interceptor_and_choosing_method_annotation_over_class()
    {
        $transactionOne = NullTransaction::start();
        $transactionInterceptor = new TransactionInterceptor();
        $transactionFactoryOne = NullTransactionFactory::createWithPredefinedTransaction($transactionOne);
        $messaging = ComponentTestBuilder::create()
            ->withReference('transactionFactory2', $transactionFactoryOne)
            ->withReference('transactionInterceptor', $transactionInterceptor)
            ->withGateway(
                GatewayProxyBuilder::create(
                    TransactionalInterceptorOnGatewayClassAndMethodExample::class,
                    TransactionalInterceptorOnGatewayClassAndMethodExample::class,
                    'invoke',
                    $inputChannel = 'inputChannel'
                )
                    ->addAroundInterceptor(
                        AroundInterceptorBuilder::createWithDirectObjectAndResolveConverters(InterfaceToCallRegistry::createEmpty(), $transactionInterceptor, 'transactional', 1, Transactional::class)
                    )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(NoReturnMessageHandler::create(), 'handle')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $messaging->getGateway(TransactionalInterceptorOnGatewayClassAndMethodExample::class)->invoke();

        $this->assertTrue($transactionOne->isCommitted());
    }

    public function test_calling_interface_with_around_interceptor_and_choosing_endpoint_annotation_over_method()
    {
        $transactionOne = NullTransaction::start();
        $transactionInterceptor = new TransactionInterceptor();
        $transactionFactoryOne = NullTransactionFactory::createWithPredefinedTransaction($transactionOne);
        $messaging = ComponentTestBuilder::create()
            ->withReference('transactionFactory3', $transactionFactoryOne)
            ->withReference('transactionInterceptor', $transactionInterceptor)
            ->withGateway(
                GatewayProxyBuilder::create(
                    TransactionalInterceptorOnGatewayClassAndMethodExample::class,
                    TransactionalInterceptorOnGatewayClassAndMethodExample::class,
                    'invoke',
                    $inputChannel = 'inputChannel'
                )
                    ->withEndpointAnnotations([new AttributeDefinition(Transactional::class, [['transactionFactory3']])])
                    ->addAroundInterceptor(
                        AroundInterceptorBuilder::createWithDirectObjectAndResolveConverters(InterfaceToCallRegistry::createEmpty(), $transactionInterceptor, 'transactional', 1, Transactional::class)
                    )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(NoReturnMessageHandler::create(), 'handle')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $messaging->getGateway(TransactionalInterceptorOnGatewayClassAndMethodExample::class)->invoke();

        $this->assertTrue($transactionOne->isCommitted());
    }

    public function test_calling_interface_with_before_and_after_interceptors()
    {
        $messaging = ComponentTestBuilder::create()
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceCalculatingService::class,
                    ServiceInterfaceCalculatingService::class,
                    'calculate',
                    $inputChannel = 'inputChannel'
                )
                    ->addBeforeInterceptor(
                        MethodInterceptor::create(
                            'interceptor0',
                            InterfaceToCall::create(CalculatingService::class, 'multiply'),
                            ServiceActivatorBuilder::createWithDirectReference(CalculatingService::create(3), 'multiply'),
                            0,
                            ''
                        )
                    )
                    ->addBeforeInterceptor(
                        MethodInterceptor::create(
                            'interceptor1',
                            InterfaceToCall::create(CalculatingService::class, 'sum'),
                            ServiceActivatorBuilder::createWithDirectReference(CalculatingService::create(3), 'sum'),
                            1,
                            ''
                        )
                    )
                    ->addAfterInterceptor(
                        MethodInterceptor::create(
                            'interceptor2',
                            InterfaceToCall::create(CalculatingService::class, 'result'),
                            ServiceActivatorBuilder::createWithDirectReference(CalculatingService::create(0), 'result'),
                            1,
                            ''
                        )
                    )
                    ->addAfterInterceptor(
                        MethodInterceptor::create(
                            'interceptor3',
                            InterfaceToCall::create(CalculatingService::class, 'multiply'),
                            ServiceActivatorBuilder::createWithDirectReference(CalculatingService::create(2), 'multiply'),
                            0,
                            ''
                        )
                    )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(CalculatingService::create(1), 'sum')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $this->assertEquals(
            20,
            $messaging->getGateway(ServiceInterfaceCalculatingService::class)->calculate(2)
        );
    }

    public function test_calling_around_interceptors_before_sending_to_error_channel()
    {
        $messageHandler = ExceptionMessageHandler::create();
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $requestChannel->subscribe($messageHandler);

        $transactionOne = NullTransaction::start();
        $transactionInterceptor = new TransactionInterceptor();
        $transactionFactoryOne = NullTransactionFactory::createWithPredefinedTransaction($transactionOne);

        $gatewayProxyBuilder = GatewayProxyBuilder::create('ref-name', ServiceInterfaceSendOnly::class, 'sendMail', $requestChannelName)
            ->withErrorChannel('some')
            ->withEndpointAnnotations([new AttributeDefinition(Transactional::class, [['transactionFactory']])])
            ->addAroundInterceptor(
                AroundInterceptorBuilder::create('transactionInterceptor', InterfaceToCall::create(TransactionInterceptor::class, 'transactional'), 1, Transactional::class, [])
            );

        $gatewayProxy = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->withChannel('some', QueueChannel::create())
            ->withReference('transactionFactory', $transactionFactoryOne)
            ->withReference('transactionInterceptor', $transactionInterceptor)
            ->buildWithProxy($gatewayProxyBuilder);

        $gatewayProxy->sendMail('test');

        $this->assertTrue($transactionOne->isRolledBack());
    }

    public function test_calling_interceptors_before_sending_to_error_channel_when_receive_throws_error()
    {
        $requestChannelName = 'request-channel';
        $replyChannel = new PollingChannelThrowingException('any');
        $exception = new RuntimeException();
        $replyChannel->withException($exception);


        $transactionOne = NullTransaction::start();
        $transactionInterceptor = new TransactionInterceptor();
        $transactionFactoryOne = NullTransactionFactory::createWithPredefinedTransaction($transactionOne);

        $gatewayProxyBuilder = GatewayProxyBuilder::create('ref-name', ServiceInterfaceReceiveOnlyWithNull::class, 'sendMail', $requestChannelName)
            ->withReplyChannel('replyChannel')
            ->withErrorChannel('some')
            ->withEndpointAnnotations([new AttributeDefinition(Transactional::class, [['transactionFactory']])])
            ->addAroundInterceptor(
                AroundInterceptorBuilder::create('transactionInterceptor', InterfaceToCall::create(TransactionInterceptor::class, 'transactional'), 1, Transactional::class, [])
            );

        $gatewayProxy = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, QueueChannel::create())
            ->withChannel('some', QueueChannel::create())
            ->withChannel('replyChannel', $replyChannel)
            ->withReference('transactionFactory', $transactionFactoryOne)
            ->withReference('transactionInterceptor', $transactionInterceptor)
            ->buildWithProxy($gatewayProxyBuilder);

        $gatewayProxy->sendMail('test');

        $this->assertTrue($transactionOne->isRolledBack());
    }

    public function test_converting_to_string()
    {
        $requestChannelName = 'inputChannel';
        $referenceName = 'ref-name';

        $this->assertEquals(
            GatewayProxyBuilder::create($referenceName, ServiceInterfaceSendOnly::class, 'sendMail', $requestChannelName),
            sprintf('Gateway - %s:%s with reference name `%s` for request channel `%s`', ServiceInterfaceSendOnly::class, 'sendMail', $referenceName, $requestChannelName)
        );
    }

    public function test_throwing_exception_if_creating_gateway_with_error_channel_and_interface_can_not_return_null()
    {
        $this->expectException(InvalidArgumentException::class);

        ComponentTestBuilder::create()
            ->withChannel('requestChannel', DirectChannel::create())
            ->withChannel('errorChannel', QueueChannel::create())
            ->build(
                GatewayProxyBuilder::create('some', ServiceInterfaceReceiveOnly::class, 'sendMail', 'requestChannel')
                    ->withErrorChannel('errorChannel')
            );
    }

    /**
     * @throws InvalidArgumentException
     * @throws MessagingException
     */
    public function test_using_message_converter_for_transformation_according_to_interface()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $replyData = MessageBuilder::withPayload('newMessage')->build();
        $messageHandler = ReplyViaHeadersMessageHandler::create($replyData);
        $requestChannel->subscribe($messageHandler);

        $gatewayBuilder = GatewayProxyBuilder::create('ref-name', FakeMessageConverterGatewayExample::class, 'execute', $requestChannelName)
            ->withParameterConverters([
                GatewayHeaderBuilder::create('some', 'some'),
                GatewayPayloadBuilder::create('amount'),
            ])
            ->withMessageConverters([
                'converter',
            ]);

        /** @var FakeMessageConverterGatewayExample $gateway */
        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->withReference('converter', new FakeMessageConverter())
            ->buildWithProxy($gatewayBuilder);

        $this->assertEquals(
            new stdClass(),
            $gateway->execute([], 100)
        );
    }

    public function test_returning_in_specific_expected_format()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $replyData = MessageBuilder::withPayload([1, 2, 3])->build();
        $messageHandler = ReplyViaHeadersMessageHandler::create($replyData);
        $requestChannel->subscribe($messageHandler);

        $gatewayBuilder = GatewayProxyBuilder::create('ref-name', StringReturningGateway::class, 'execute', $requestChannelName)
            ->withParameterConverters([
                GatewayHeaderBuilder::create('replyMediaType', MessageHeaders::REPLY_CONTENT_TYPE),
            ]);

        /** @var StringReturningGateway $gateway */
        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->withReference(ConversionService::REFERENCE_NAME, AutoCollectionConversionService::createWith([new ArrayToJsonConverter()]))
            ->buildWithProxy($gatewayBuilder);

        $this->assertEquals(
            '[1,2,3]',
            $gateway->execute(MediaType::APPLICATION_JSON)
        );
    }

    public function test_converting_reply_from_one_returned_media_type_to_another()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $replyData = MessageBuilder::withPayload([1, 2, 3])->build();
        $messageHandler = ReplyViaHeadersMessageHandler::create($replyData);
        $requestChannel->subscribe($messageHandler);

        $gatewayBuilder = GatewayProxyBuilder::create('ref-name', MessageReturningGateway::class, 'execute', $requestChannelName)
            ->withParameterConverters([
                GatewayHeaderBuilder::create('replyMediaType', MessageHeaders::REPLY_CONTENT_TYPE),
            ]);

        /** @var MessageReturningGateway $gateway */
        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->withReference(ConversionService::REFERENCE_NAME, AutoCollectionConversionService::createWith([new ArrayToJsonConverter()]))
            ->buildWithProxy($gatewayBuilder);

        $message = $gateway->execute(MediaType::APPLICATION_JSON);
        $this->assertEquals('[1,2,3]', $message->getPayload());
        $this->assertEquals(MediaType::APPLICATION_JSON, $message->getHeaders()->getContentType()->toString());
    }

    public function test_returning_with_specific_content_type_if_defined_in_reply_message()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $requestChannel->subscribe(DataReturningService::createServiceActivatorWithReturnMessage('[1,2,3]', [MessageHeaders::CONTENT_TYPE => MediaType::APPLICATION_JSON]));

        $expectedMediaType = MediaType::createApplicationXPHPWithTypeParameter(TypeDescriptor::ARRAY)->toString();
        $gatewayBuilder = GatewayProxyBuilder::create('ref-name', MessageReturningGateway::class, 'executeNoParameter', $requestChannelName)
            ->withParameterConverters([
                GatewayHeaderValueBuilder::create(MessageHeaders::REPLY_CONTENT_TYPE, $expectedMediaType),
            ]);

        /** @var MessageReturningGateway $gateway */
        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->withReference(ConversionService::REFERENCE_NAME, AutoCollectionConversionService::createWith([new JsonToArrayConverter()]))
            ->buildWithProxy($gatewayBuilder);

        $message = $gateway->executeNoParameter();
        $this->assertEquals([1, 2, 3], $message->getPayload());
        $this->assertEquals($expectedMediaType, $message->getHeaders()->getContentType()->toString());
    }

    public function test_returning_with_specific_content_type_based_on_invoked_interface_return_type_when_array()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $requestChannel->subscribe(
            DataReturningService::createServiceActivatorWithReturnMessage([new stdClass(), new stdClass()], [
                MessageHeaders::CONTENT_TYPE => MediaType::createApplicationXPHPWithTypeParameter(TypeDescriptor::createCollection(stdClass::class)->toString()),
            ])
        );

        $expectedMediaType = MediaType::createApplicationXPHPWithTypeParameter(TypeDescriptor::ARRAY)->toString();
        $gatewayBuilder = GatewayProxyBuilder::create('ref-name', MixedReturningGateway::class, 'executeNoParameter', $requestChannelName)
            ->withParameterConverters([
                GatewayHeaderValueBuilder::create(MessageHeaders::REPLY_CONTENT_TYPE, $expectedMediaType),
            ]);

        /** @var MessageReturningGateway $gateway */
        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->withReference(
                ConversionService::REFERENCE_NAME,
                InMemoryConversionService::createWithoutConversion()
                    ->registerConversion(
                        [new stdClass(), new stdClass()],
                        MediaType::APPLICATION_X_PHP,
                        TypeDescriptor::createCollection(stdClass::class)->toString(),
                        MediaType::APPLICATION_X_PHP_ARRAY,
                        TypeDescriptor::ARRAY,
                        [1, 1]
                    )
            )
            ->buildWithProxy($gatewayBuilder);

        $this->assertEquals([1, 1], $gateway->executeNoParameter());
    }

    public function test_returning_generator_without_any_type_defined()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $requestChannel->subscribe(DataReturningService::createServiceActivatorWithGenerator([1, 2]));

        $gatewayBuilder = GatewayProxyBuilder::create('ref-name', IteratorReturningGateway::class, 'executeIteratorWithoutType', $requestChannelName);
        $expectedResultSet = [1, 2];

        /** @var IteratorReturningGateway $gateway */
        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->buildWithProxy($gatewayBuilder);

        $resultSet = [];
        foreach ($gateway->executeIteratorWithoutType() as $item) {
            $resultSet[] = $item;
        }

        $this->assertEquals($expectedResultSet, $resultSet);
    }

    public function test_returning_generator_without_conversion()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $requestChannel->subscribe(DataReturningService::createServiceActivatorWithGenerator([1, 2]));

        $gatewayBuilder = GatewayProxyBuilder::create('ref-name', IteratorReturningGateway::class, 'executeIteratorWithScalarType', $requestChannelName);
        $expectedResultSet = [1, 2];

        /** @var IteratorReturningGateway $gateway */
        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->buildWithProxy($gatewayBuilder);

        $resultSet = [];
        foreach ($gateway->executeIteratorWithScalarType() as $item) {
            $resultSet[] = $item;
        }

        $this->assertEquals($expectedResultSet, $resultSet);
    }

    public function test_returning_generator_without_conversion_due_to_complex_return_type()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $expectedResultSet = [new stdClass(), 5];
        $requestChannel->subscribe(DataReturningService::createServiceActivatorWithGenerator($expectedResultSet));

        $gatewayBuilder = GatewayProxyBuilder::create('ref-name', IteratorReturningGateway::class, 'executeWithAdvancedIterator', $requestChannelName);

        /** @var IteratorReturningGateway $gateway */
        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->buildWithProxy($gatewayBuilder);

        $resultSet = [];
        foreach ($gateway->executeWithAdvancedIterator() as $item) {
            $resultSet[] = $item;
        }

        $this->assertEquals($expectedResultSet, $resultSet);
    }

    public function test_returning_generator_with_conversion()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $requestChannel->subscribe(DataReturningService::createServiceActivatorWithGenerator([1, 2]));

        $gatewayBuilder = GatewayProxyBuilder::create('ref-name', IteratorReturningGateway::class, 'executeIterator', $requestChannelName);
        $resultOne = new stdClass();
        $resultOne->id = 1;
        $resultTwo = new stdClass();
        $resultTwo->id = 2;
        $expectedResultSet = [$resultOne, $resultTwo];

        /** @var IteratorReturningGateway $gateway */
        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->withReference(
                ConversionService::REFERENCE_NAME,
                InMemoryConversionService::createWithoutConversion()
                    ->registerConversion(
                        1,
                        MediaType::APPLICATION_X_PHP,
                        TypeDescriptor::createIntegerType()->toString(),
                        MediaType::APPLICATION_X_PHP,
                        TypeDescriptor::create(stdClass::class)->toString(),
                        $resultOne
                    )
                    ->registerConversion(
                        2,
                        MediaType::APPLICATION_X_PHP,
                        TypeDescriptor::createIntegerType()->toString(),
                        MediaType::APPLICATION_X_PHP,
                        TypeDescriptor::create(stdClass::class)->toString(),
                        $resultTwo
                    )
            )
            ->buildWithProxy($gatewayBuilder);

        $resultSet = [];
        foreach ($gateway->executeIterator() as $item) {
            $resultSet[] = $item;
        }

        $this->assertEquals($expectedResultSet, $resultSet);
    }

    public function test_forcing_conversion_when_target_type_is_array_and_source_is_also_array()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $requestChannel->subscribe(DataReturningService::createServiceActivatorWithReturnMessage([
            Uuid::fromString('e7019549-9733-45a3-b088-783de2b2357f'),
            Uuid::fromString('30ae8690-c729-447c-b9f9-bafd668cb01e'),
        ], [MessageHeaders::CONTENT_TYPE => MediaType::APPLICATION_X_PHP_ARRAY]));

        $expectedMediaType = MediaType::APPLICATION_X_PHP_ARRAY;
        /** @var MessageReturningGateway $gateway */
        $gatewayBuilder = GatewayProxyBuilder::create('ref-name', MessageReturningGateway::class, 'executeNoParameter', $requestChannelName)
            ->withReplyContentType($expectedMediaType);

        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->withReference(ConversionService::REFERENCE_NAME, AutoCollectionConversionService::createWith([new ExampleStdClassConverter()]))
            ->buildWithProxy($gatewayBuilder);

        $message = $gateway->executeNoParameter();
        $this->assertIsNotArray($message->getPayload());
        $this->assertNotInstanceOf(Uuid::class, $message->getPayload());
    }

    public function test_converting_according_to_interface_to_call_return_type()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $requestChannel->subscribe(DataReturningService::createServiceActivator('e7019549-9733-45a3-b088-783de2b2357f'));

        $gatewayBuilder = GatewayProxyBuilder::create('ref-name', UuidReturningGateway::class, 'executeNoParameter', $requestChannelName);

        /** @var MessageReturningGateway $gateway */
        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->withReference(ConversionService::REFERENCE_NAME, AutoCollectionConversionService::createWith([new StringToUuidConverter()]))
            ->buildWithProxy($gatewayBuilder);

        $this->assertEquals(Uuid::fromString('e7019549-9733-45a3-b088-783de2b2357f'), $gateway->executeNoParameter());
    }

    public function test_not_converting_when_return_type_is_message()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $requestChannel->subscribe(DataReturningService::createServiceActivatorWithReturnMessage([1, 2, 3], [
            MessageHeaders::CONTENT_TYPE => MediaType::createApplicationXPHPWithTypeParameter('array')->toString(),
        ]));

        $gatewayBuilder = GatewayProxyBuilder::create('ref-name', BeforeSendGateway::class, 'execute', $requestChannelName);

        /** @var MessageReturningGateway $gateway */
        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->withReference(ConversionService::REFERENCE_NAME, AutoCollectionConversionService::createWith([new ExceptionalConverter()]))
            ->buildWithProxy($gatewayBuilder);

        $this->assertInstanceOf(Message::class, $gateway->execute(MessageBuilder::withPayload('b')->build()));
    }

    public function test_not_converting_if_reply_has_already_expected_type()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $replyData = '[1,2,3]';
        $mediaType = MediaType::APPLICATION_JSON;
        $requestChannel->subscribe(DataReturningService::createServiceActivatorWithReturnMessage($replyData, [MessageHeaders::CONTENT_TYPE => $mediaType]));

        /** @var MessageReturningGateway $gateway */
        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->buildWithProxy(GatewayProxyBuilder::create('ref-name', MessageReturningGateway::class, 'executeNoParameter', $requestChannelName)
                ->withReplyContentType($mediaType));

        $replyMessage = $gateway->executeNoParameter(MessageBuilder::withPayload('some')->setHeader('token', '123')->build());

        $this->assertEquals(
            $replyData,
            $replyMessage->getPayload()
        );
        $this->assertEquals(
            $mediaType,
            $replyMessage->getHeaders()->get(MessageHeaders::CONTENT_TYPE)
        );
    }

    public function test_throwing_exception_if_converter_fro_reply_media_type_is_missing()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $replyData = 2;
        $requestChannel->subscribe(DataReturningService::createServiceActivator($replyData));

        /** @var MessageReturningGateway $gateway */
        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->buildWithProxy(GatewayProxyBuilder::create('ref-name', UuidReturningGateway::class, 'executeNoParameter', $requestChannelName)
                ->withReplyContentType(MediaType::APPLICATION_JSON));

        $this->expectException(InvalidArgumentException::class);

        $gateway->executeNoParameter();
    }

    public function test_executing_interface_with_parameters_and_instead_of_parameters_message_is_given()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $replyData = '[1,2,3]';
        $mediaType = MediaType::APPLICATION_JSON;
        $requestChannel->subscribe(DataReturningService::createServiceActivatorWithReturnMessage($replyData, [MessageHeaders::CONTENT_TYPE => $mediaType]));

        /** @var NonProxyGateway $gateway */
        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->build(GatewayProxyBuilder::create('ref-name', MessageReturningGateway::class, 'executeWithMetadataWithDefault', $requestChannelName)
                ->withReplyContentType($mediaType));

        $replyMessage = $gateway->execute([MessageBuilder::withPayload('some')->build()]);

        $this->assertEquals(
            $replyData,
            $replyMessage->getPayload()
        );
    }

    public function test_executing_with_default_parameters_auto_resolved()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $replyData = '[1,2,3]';
        $mediaType = MediaType::APPLICATION_JSON;
        $requestChannel->subscribe(DataReturningService::createServiceActivatorWithReturnMessage($replyData, [MessageHeaders::CONTENT_TYPE => $mediaType]));

        /** @var NonProxyGateway $gateway */
        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->build(GatewayProxyBuilder::create('ref-name', MessageReturningGateway::class, 'executeWithMetadataWithDefault', $requestChannelName)
                ->withReplyContentType($mediaType));

        $replyMessage = $gateway->execute([['some']]);

        $this->assertEquals(
            $replyData,
            $replyMessage->getPayload()
        );
    }

    public function test_executing_with_passed_null()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $replyData = '[1,2,3]';
        $mediaType = MediaType::APPLICATION_JSON;
        $requestChannel->subscribe(DataReturningService::createServiceActivatorWithReturnMessage($replyData, [MessageHeaders::CONTENT_TYPE => $mediaType]));

        /** @var NonProxyGateway $gateway */
        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->build(GatewayProxyBuilder::create('ref-name', MessageReturningGateway::class, 'executeWithMetadataWithNull', $requestChannelName)
                ->withParameterConverters([
                    GatewayHeaderBuilder::create('metadata', 'someData'),
                ])
                ->withReplyContentType($mediaType));

        /** @var Message $replyMessage */
        $replyMessage = $gateway->execute([['some'], null]);

        $this->assertFalse($replyMessage->getHeaders()->containsKey('someData'));
    }

    public function test_throwing_exception_when_there_is_not_enough_parameters_given()
    {
        $requestChannelName = 'request-channel';
        $requestChannel = DirectChannel::create();
        $requestChannel->subscribe(DataReturningService::createServiceActivator('test'));
        $mediaType = MediaType::APPLICATION_JSON;

        /** @var NonProxyGateway $gateway */
        $gateway = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->build(GatewayProxyBuilder::create('ref-name', MessageReturningGateway::class, 'executeWithMetadata', $requestChannelName)
                ->withReplyContentType($mediaType));

        $this->expectException(InvalidArgumentException::class);

        $gateway->execute([]);
    }
}
