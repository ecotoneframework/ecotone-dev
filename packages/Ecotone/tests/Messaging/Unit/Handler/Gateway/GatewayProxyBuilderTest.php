<?php

namespace Test\Ecotone\Messaging\Unit\Handler\Gateway;

use Ecotone\Api\PollingMetadata;
use Ecotone\Api\SimpleMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlingException;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagingException;
use Ecotone\Messaging\Support\ErrorMessage;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Messaging\Transaction\Null\NullTransaction;
use Ecotone\Messaging\Transaction\Null\NullTransactionFactory;
use Ecotone\Messaging\Transaction\Transactional;
use Ecotone\Messaging\Transaction\TransactionInterceptor;
use Ecotone\Test\ComponentTestBuilder;
use Ecotone\Test\InMemoryConversionService;
use RuntimeException;
use stdClass;
use Test\Ecotone\Messaging\Fixture\Channel\PollingChannelThrowingException;
use Test\Ecotone\Messaging\Fixture\Handler\ExceptionMessageHandler;
use Test\Ecotone\Messaging\Fixture\Handler\Gateway\IteratorReturningGateway;
use Test\Ecotone\Messaging\Fixture\Handler\Gateway\MixedReturningGateway;
use Test\Ecotone\Messaging\Fixture\Handler\Gateway\StringReturningGateway;
use Test\Ecotone\Messaging\Fixture\Handler\NoReturnMessageHandler;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\TransactionalInterceptorOnGatewayClassAndMethodExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\TransactionalInterceptorOnGatewayClassExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\TransactionalInterceptorOnGatewayMethodExample;
use Test\Ecotone\Messaging\Fixture\MessageConverter\FakeMessageConverter;
use Test\Ecotone\Messaging\Fixture\MessageConverter\FakeMessageConverterGatewayExample;
use Test\Ecotone\Messaging\Fixture\Service\CalculatingService;
use Test\Ecotone\Messaging\Fixture\Service\Gateway\TicketCreator;
use Test\Ecotone\Messaging\Fixture\Service\Gateway\TicketService;
use Test\Ecotone\Messaging\Fixture\Service\ServiceExpectingNoArguments;
use Test\Ecotone\Messaging\Fixture\Service\ServiceExpectingOneArgument;
use Test\Ecotone\Messaging\Fixture\Service\ServiceInterface\ServiceInterfaceCalculatingService;
use Test\Ecotone\Messaging\Fixture\Service\ServiceInterface\ServiceInterfaceReceiveOnly;
use Test\Ecotone\Messaging\Fixture\Service\ServiceInterface\ServiceInterfaceReceiveOnlyWithNull;
use Test\Ecotone\Messaging\Fixture\Service\ServiceInterface\ServiceInterfaceSendOnly;
use Test\Ecotone\Messaging\Fixture\Service\ServiceInterface\ServiceInterfaceSendOnlyWithTwoArguments;
use Test\Ecotone\Messaging\Fixture\Service\ServiceInterface\ServiceInterfaceWithFutureReceive;
use Test\Ecotone\Messaging\Fixture\Service\ServiceInterface\ServiceWithMixed;
use Test\Ecotone\Messaging\Unit\MessagingTestCase;

/**
 * Class GatewayProxyBuilderTest
 * @package Ecotone\Messaging\Config
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 *
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class GatewayProxyBuilderTest extends MessagingTestCase
{
    public function test_running_gateway(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [TicketCreator::class, TicketService::class],
            [new TicketService()]
        );

        /** @var TicketCreator $ticketCreator */
        $ticketCreator = $ecotoneLite->getGateway(TicketCreator::class);

        $ticketCreator->create('some');

        $this->assertEquals(
            ['some'],
            $ecotoneLite->sendQueryWithRouting('getTickets')
        );
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

    public function test_calling_reply_queue_with_time_out()
    {
        $payload = 'replyData';
        $replyMessage = MessageBuilder::withPayload($payload)->build();
        $replyChannel = new class ($replyMessage) extends QueueChannel {
            public function __construct(private Message $replyMessage)
            {
                parent::__construct('');
            }
            public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
            {
                if ($pollingMetadata->getFixedRateInMilliseconds() === 1) {
                    return $this->replyMessage;
                }
                throw InvalidArgumentException::create("Timeout should be 1, but got {$pollingMetadata->getFixedRateInMilliseconds()}");
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

        $messaging->getMessageChannel($replyChannelName)->send(ErrorMessage::create(MessageBuilder::withPayload('some')->build(), MessageHandlingException::create('error occurred')));

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

        $messaging->getMessageChannel($replyChannelName)->send(ErrorMessage::create(MessageBuilder::withPayload('some')->build(), MessageHandlingException::create('error occurred')));

        $this->expectException(MessageHandlingException::class);

        $messaging->getGateway(ServiceInterfaceWithFutureReceive::class)
            ->someLongRunningWork()
            ->resolve();
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

        $messaging->getMessageChannel($replyChannelName)->send(ErrorMessage::create(MessageBuilder::withPayload('some')->build(), MessageHandlingException::create('error occurred')));

        $messaging->getGateway(ServiceInterfaceReceiveOnlyWithNull::class)->sendMail();

        $this->assertInstanceOf(
            ErrorMessage::class,
            $messaging->receiveMessageFrom($errorChannelName)
        );
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
        $beforeInterceptor1Ref = 'beforeInterceptor1';
        $beforeInterceptor2Ref = 'beforeInterceptor2';
        $afterInterceptor1Ref = 'afterInterceptor1';
        $afterInterceptor2Ref = 'afterInterceptor2';

        $beforeInterceptor1 = CalculatingService::create(3);
        $beforeInterceptor2 = CalculatingService::create(3);
        $afterInterceptor1 = CalculatingService::create(0);
        $afterInterceptor2 = CalculatingService::create(2);

        $messaging = ComponentTestBuilder::create()
            ->withReference($beforeInterceptor1Ref, $beforeInterceptor1)
            ->withReference($beforeInterceptor2Ref, $beforeInterceptor2)
            ->withReference($afterInterceptor1Ref, $afterInterceptor1)
            ->withReference($afterInterceptor2Ref, $afterInterceptor2)
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceCalculatingService::class,
                    ServiceInterfaceCalculatingService::class,
                    'calculate',
                    $inputChannel = 'inputChannel'
                )
            )
            ->withBeforeInterceptor(
                MethodInterceptorBuilder::create(
                    Reference::to($beforeInterceptor1Ref),
                    InterfaceToCall::create(CalculatingService::class, 'multiply'),
                    0,
                    ServiceInterfaceCalculatingService::class
                )
            )
            ->withBeforeInterceptor(
                MethodInterceptorBuilder::create(
                    Reference::to($beforeInterceptor2Ref),
                    InterfaceToCall::create(CalculatingService::class, 'sum'),
                    1,
                    ServiceInterfaceCalculatingService::class
                )
            )
            ->withAfterInterceptor(
                MethodInterceptorBuilder::create(
                    Reference::to($afterInterceptor1Ref),
                    InterfaceToCall::create(CalculatingService::class, 'result'),
                    1,
                    ServiceInterfaceCalculatingService::class
                )
            )
            ->withAfterInterceptor(
                MethodInterceptorBuilder::create(
                    Reference::to($afterInterceptor2Ref),
                    InterfaceToCall::create(CalculatingService::class, 'multiply'),
                    0,
                    ServiceInterfaceCalculatingService::class
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
        $transactionOne = NullTransaction::start();
        $transactionInterceptor = new TransactionInterceptor();
        $transactionFactoryOne = NullTransactionFactory::createWithPredefinedTransaction($transactionOne);
        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::createQueueChannel('some'))
            ->withReference('transactionFactory', $transactionFactoryOne)
            ->withReference('transactionInterceptor', $transactionInterceptor)
            ->withGateway(
                GatewayProxyBuilder::create(
                    TransactionalInterceptorOnGatewayClassExample::class,
                    TransactionalInterceptorOnGatewayClassExample::class,
                    'invoke',
                    $inputChannel = 'inputChannel'
                )
                    ->withErrorChannel('some')
                    ->addAroundInterceptor(
                        AroundInterceptorBuilder::createWithDirectObjectAndResolveConverters(InterfaceToCallRegistry::createEmpty(), $transactionInterceptor, 'transactional', 1, Transactional::class)
                    )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ExceptionMessageHandler::create(), 'handle')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $messaging->getGateway(TransactionalInterceptorOnGatewayClassExample::class)->invoke();

        $this->assertFalse($transactionOne->isCommitted());
        $this->assertTrue($transactionOne->isRolledBack());
        $this->assertNotNull($messaging->receiveMessageFrom('some'));
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
        $messaging = ComponentTestBuilder::create()
            ->withChannel(SimpleMessageChannelBuilder::create($requestChannelName, $replyChannel))
            ->withChannel(SimpleMessageChannelBuilder::createQueueChannel('some'))
            ->withReference('transactionFactory', $transactionFactoryOne)
            ->withReference('transactionInterceptor', $transactionInterceptor)
            ->withGateway(
                GatewayProxyBuilder::create(
                    ServiceInterfaceReceiveOnlyWithNull::class,
                    ServiceInterfaceReceiveOnlyWithNull::class,
                    'sendMail',
                    $inputChannel = 'inputChannel'
                )
                    ->withEndpointAnnotations([new AttributeDefinition(Transactional::class, [['transactionFactory']])])
                    ->withErrorChannel('some')
                    ->withReplyChannel($requestChannelName)
                    ->addAroundInterceptor(
                        AroundInterceptorBuilder::createWithDirectObjectAndResolveConverters(InterfaceToCallRegistry::createEmpty(), $transactionInterceptor, 'transactional', 1, Transactional::class)
                    )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(NoReturnMessageHandler::create(), 'handle')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $messaging->getGateway(ServiceInterfaceReceiveOnlyWithNull::class)->sendMail();

        $this->assertFalse($transactionOne->isCommitted());
        $this->assertTrue($transactionOne->isRolledBack());
        $this->assertNotNull($messaging->receiveMessageFrom('some'));
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

    /**
     * @throws InvalidArgumentException
     * @throws MessagingException
     */
    public function test_using_message_converter_for_transformation_according_to_interface()
    {
        $messaging = ComponentTestBuilder::create()
            ->withReference('converter', new FakeMessageConverter())
            ->withGateway(
                GatewayProxyBuilder::create(
                    FakeMessageConverterGatewayExample::class,
                    FakeMessageConverterGatewayExample::class,
                    'execute',
                    $inputChannel = 'inputChannel'
                )
                    ->withParameterConverters([
                        GatewayHeaderBuilder::create('some', 'some'),
                        GatewayPayloadBuilder::create('amount'),
                    ])
                    ->withMessageConverters([
                        'converter',
                    ])
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ServiceExpectingOneArgument::create(), 'withMessage')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $this->assertEquals(
            new stdClass(),
            $messaging->getGateway(FakeMessageConverterGatewayExample::class)
                ->execute([], 'test')
        );
    }

    public function test_returning_with_specific_content_type_if_defined_in_reply_message()
    {
        $messaging = ComponentTestBuilder::create()
            ->withGateway(
                GatewayProxyBuilder::create(
                    StringReturningGateway::class,
                    StringReturningGateway::class,
                    'executeWithPayloadAndHeaders',
                    $inputChannel = 'inputChannel'
                )
                    ->withParameterConverters([
                        GatewayHeaderBuilder::create('replyMediaType', MessageHeaders::REPLY_CONTENT_TYPE),
                    ])
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ServiceExpectingOneArgument::create(), 'withMessage')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $this->assertEquals(
            '[1,2,3]',
            $messaging->getGateway(StringReturningGateway::class)
                ->executeWithPayloadAndHeaders('[1,2,3]', [MessageHeaders::CONTENT_TYPE => MediaType::APPLICATION_JSON], MediaType::APPLICATION_JSON)
        );
    }

    public function test_returning_with_specific_content_type_based_on_invoked_interface_return_type_when_array()
    {
        $messaging = ComponentTestBuilder::create()
            ->withConverter(
                InMemoryConversionService::createWithConversion(
                    $requestData = [new stdClass(), new stdClass()],
                    MediaType::APPLICATION_X_PHP,
                    Type::createCollection(stdClass::class)->toString(),
                    MediaType::APPLICATION_X_PHP_ARRAY,
                    'array',
                    $replyData = [1, 1]
                )
            )
            ->withGateway(
                GatewayProxyBuilder::create(
                    MixedReturningGateway::class,
                    MixedReturningGateway::class,
                    'executeWithPayload',
                    $inputChannel = 'inputChannel'
                )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ServiceExpectingOneArgument::create(), 'withMessage')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $this->assertEquals(
            $replyData,
            $messaging->getGateway(MixedReturningGateway::class)
                ->executeWithPayload($requestData)
        );
    }

    /**
     * Attempted conversion to a real #[MessageGateway]/#[Converter] scenario: an
     * IteratorGateway with `@return iterable<stdClass>` and a real registered
     * #[Converter] converting int->stdClass. The gateway's generator reply
     * passed the raw ints through unconverted -- per-element conversion for a
     * docblock-typed `iterable` return on an annotation-discovered gateway
     * interface does not appear to be honoured the same way InterfaceToCall::create()
     * (used directly by this ComponentTestBuilder-based test) resolves it.
     * Left as a genuine finding rather than forcing a false-positive conversion.
     */
    public function test_returning_generator_with_conversion()
    {
        $resultOne = new stdClass();
        $resultOne->id = 1;
        $resultTwo = new stdClass();
        $resultTwo->id = 2;

        $messaging = ComponentTestBuilder::create()
            ->withConverter(
                InMemoryConversionService::createWithoutConversion()
                    ->registerConversion(
                        $resultOne->id,
                        MediaType::APPLICATION_X_PHP,
                        Type::int()->toString(),
                        MediaType::APPLICATION_X_PHP,
                        Type::create(stdClass::class)->toString(),
                        $resultOne
                    )
                    ->registerConversion(
                        $resultTwo->id,
                        MediaType::APPLICATION_X_PHP,
                        Type::int()->toString(),
                        MediaType::APPLICATION_X_PHP,
                        Type::create(stdClass::class)->toString(),
                        $resultTwo
                    )
            )
            ->withGateway(
                GatewayProxyBuilder::create(
                    IteratorReturningGateway::class,
                    IteratorReturningGateway::class,
                    'executeIterator',
                    $inputChannel = 'inputChannel'
                )
            )
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ServiceExpectingOneArgument::create(), 'withMessage')
                    ->withInputChannelName($inputChannel)
            )
            ->build();

        $resultSet = [];
        foreach ($messaging->getGateway(IteratorReturningGateway::class)->executeIterator([$resultOne->id, $resultTwo->id]) as $item) {
            $resultSet[] = $item;
        }

        $this->assertEquals([$resultOne, $resultTwo], $resultSet);
    }

}
