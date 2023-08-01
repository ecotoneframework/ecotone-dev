<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\Processor;

use Ecotone\Messaging\Config\InMemoryChannelResolver;
use Ecotone\Messaging\Conversion\AutoCollectionConversionService;
use Ecotone\Messaging\Conversion\JsonToArray\JsonToArrayConverter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Conversion\SerializedToObject\DeserializingConverter;
use Ecotone\Messaging\Conversion\StringToUuid\StringToUuidConverter;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\MessageConverterBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Handler\Processor\WrapWithMessageBuildProcessor;
use Ecotone\Messaging\Handler\ReferenceNotFoundException;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagingException;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ramsey\Uuid\Uuid;
use ReflectionException;
use stdClass;
use Test\Ecotone\Messaging\Fixture\Behat\Ordering\Order;
use Test\Ecotone\Messaging\Fixture\Behat\Ordering\OrderConfirmation;
use Test\Ecotone\Messaging\Fixture\Behat\Ordering\OrderProcessor;
use Test\Ecotone\Messaging\Fixture\Converter\StringToUuidClassConverter;
use Test\Ecotone\Messaging\Fixture\Handler\ExampleService;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallMultipleUnorderedArgumentsInvocationInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithAnnotationFromMethodInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithInterceptedObjectInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithPassThroughInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithReferenceSearchServiceExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithStdClassInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\StubCallSavingService;
use Test\Ecotone\Messaging\Fixture\Service\CalculatingService;
use Test\Ecotone\Messaging\Fixture\Service\ServiceExpectingMessageAndReturningMessage;
use Test\Ecotone\Messaging\Fixture\Service\ServiceExpectingOneArgument;
use Test\Ecotone\Messaging\Fixture\Service\ServiceExpectingThreeArguments;
use Test\Ecotone\Messaging\Fixture\Service\ServiceExpectingTwoArguments;
use Test\Ecotone\Messaging\Unit\MessagingTest;

/**
 * Class MethodInvocationTest
 * @package Ecotone\Messaging\Handler\ServiceActivator
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * @internal
 */
class MethodInvokerTest extends MessagingTest
{
    public function test_throwing_exception_if_not_enough_arguments_provided()
    {
        $this->expectException(InvalidArgumentException::class);

        $service = ServiceExpectingTwoArguments::create();
        $interfaceToCall = InterfaceToCall::create($service, 'withoutReturnValue');

        MethodInvoker::createWith($interfaceToCall, $service, [], InMemoryReferenceSearchService::createEmpty());
    }

    public function test_invoking_service()
    {
        $serviceExpectingOneArgument = ServiceExpectingOneArgument::create();
        $interfaceToCall = InterfaceToCall::create($serviceExpectingOneArgument, 'withoutReturnValue');

        $methodInvocation = MethodInvoker::createWith($interfaceToCall, $serviceExpectingOneArgument, [
            PayloadBuilder::create('name'),
        ], InMemoryReferenceSearchService::createEmpty());

        $methodInvocation->executeEndpoint(MessageBuilder::withPayload('some')->build());

        $this->assertTrue($serviceExpectingOneArgument->wasCalled(), 'Method was not called');
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReferenceNotFoundException
     * @throws MessagingException
     */
    public function test_not_changing_content_type_of_message_if_message_is_return_type()
    {
        $serviceExpectingOneArgument = ServiceExpectingMessageAndReturningMessage::create('test');
        $interfaceToCall = InterfaceToCall::create($serviceExpectingOneArgument, 'send');

        $methodInvocation = MethodInvoker::createWith($interfaceToCall, $serviceExpectingOneArgument, [
            MessageConverterBuilder::create('message'),
        ], InMemoryReferenceSearchService::createEmpty());

        $this->assertMessages(
            MessageBuilder::withPayload('test')
                ->build(),
            $methodInvocation->executeEndpoint(MessageBuilder::withPayload('some')->build())
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws MessagingException
     */
    public function test_invoking_service_with_return_value_from_header()
    {
        $serviceExpectingOneArgument = ServiceExpectingOneArgument::create();
        $interfaceToCall = InterfaceToCall::create($serviceExpectingOneArgument, 'withReturnValue');
        $headerName = 'token';
        $headerValue = '123X';

        $methodInvocation = MethodInvoker::createWith($interfaceToCall, $serviceExpectingOneArgument, [
            HeaderBuilder::create('name', $headerName),
        ], InMemoryReferenceSearchService::createEmpty());

        $this->assertEquals(
            $headerValue,
            $methodInvocation->executeEndpoint(
                MessageBuilder::withPayload('some')
                    ->setHeader($headerName, $headerValue)
                    ->build()
            )
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws MessagingException
     */
    public function test_if_method_requires_one_argument_and_there_was_not_passed_any_then_use_payload_one_as_default()
    {
        $serviceExpectingOneArgument = ServiceExpectingOneArgument::create();
        $interfaceToCall = InterfaceToCall::create($serviceExpectingOneArgument, 'withReturnValue');

        $methodInvocation = MethodInvoker::createWith($interfaceToCall, $serviceExpectingOneArgument, [], InMemoryReferenceSearchService::createEmpty());

        $payload = 'some';

        $this->assertEquals(
            $payload,
            $methodInvocation->executeEndpoint(
                MessageBuilder::withPayload($payload)
                    ->build()
            )
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws MessagingException
     */
    public function test_if_method_requires_two_argument_and_there_was_not_passed_any_then_use_payload_and_headers_if_possible_as_default()
    {
        $serviceExpectingOneArgument = ServiceExpectingTwoArguments::create();
        $interfaceToCall = InterfaceToCall::create($serviceExpectingOneArgument, 'payloadAndHeaders');

        $methodInvocation = MethodInvoker::createWith($interfaceToCall, $serviceExpectingOneArgument, [], InMemoryReferenceSearchService::createEmpty());

        $payload = 'some';

        $this->assertEquals(
            $payload,
            $methodInvocation->executeEndpoint(
                MessageBuilder::withPayload($payload)
                    ->build()
            )
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws MessagingException
     */
    public function test_throwing_exception_if_passed_wrong_argument_names()
    {
        $serviceExpectingOneArgument = ServiceExpectingOneArgument::create();
        $interfaceToCall = InterfaceToCall::create($serviceExpectingOneArgument, 'withoutReturnValue');

        $this->expectException(InvalidArgumentException::class);

        MethodInvoker::createWith($interfaceToCall, $serviceExpectingOneArgument, [
            PayloadBuilder::create('wrongName'),
        ], InMemoryReferenceSearchService::createEmpty());
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws MessagingException
     */
    public function test_invoking_service_with_multiple_not_ordered_arguments()
    {
        $serviceExpectingThreeArgument = ServiceExpectingThreeArguments::create();
        $interfaceToCall = InterfaceToCall::create($serviceExpectingThreeArgument, 'withReturnValue');

        $methodInvocation = MethodInvoker::createWith($interfaceToCall, $serviceExpectingThreeArgument, [
            HeaderBuilder::create('surname', 'personSurname'),
            HeaderBuilder::create('age', 'personAge'),
            PayloadBuilder::create('name'),
        ], InMemoryReferenceSearchService::createEmpty());

        $this->assertEquals(
            'johnybilbo13',
            $methodInvocation->executeEndpoint(
                MessageBuilder::withPayload('johny')
                    ->setHeader('personSurname', 'bilbo')
                    ->setHeader('personAge', 13)
                    ->build()
            )
        );
    }

    public function test_invoking_with_payload_conversion()
    {
        $referenceSearchService = InMemoryReferenceSearchService::createWith([
            AutoCollectionConversionService::REFERENCE_NAME => AutoCollectionConversionService::createWith([
                new DeserializingConverter(),
            ]),
        ]);
        $interfaceToCall = InterfaceToCall::create(new OrderProcessor(), 'processOrder');

        $methodInvocation =
            WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                MethodInvoker::createWith($interfaceToCall, new OrderProcessor(), [
                    PayloadBuilder::create('order'),
                ], $referenceSearchService),
                $referenceSearchService
            );

        $replyMessage = $methodInvocation->executeEndpoint(
            MessageBuilder::withPayload(addslashes(serialize(Order::create('1', 'correct'))))
                ->setContentType(MediaType::createApplicationXPHPSerialized())
                ->build()
        );

        $this->assertMessages(
            MessageBuilder::withPayload(OrderConfirmation::fromOrder(Order::create('1', 'correct')))
                ->setContentType(MediaType::createApplicationXPHPWithTypeParameter(OrderConfirmation::class))
                ->build(),
            $replyMessage
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReferenceNotFoundException
     * @throws MessagingException
     */
    public function test_throwing_exception_if_cannot_convert_to_php_media_type()
    {
        $referenceSearchService = InMemoryReferenceSearchService::createWith([
            AutoCollectionConversionService::REFERENCE_NAME => AutoCollectionConversionService::createWith([]),
        ]);
        $service   = new OrderProcessor();
        $interfaceToCall = InterfaceToCall::create($service, 'processOrder');

        $methodInvocation =
            WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                MethodInvoker::createWith(
                    $interfaceToCall,
                    $service,
                    [
                        PayloadBuilder::create('order'),
                    ],
                    $referenceSearchService
                ),
                $referenceSearchService
            );

        $this->expectException(InvalidArgumentException::class);

        $methodInvocation->executeEndpoint(
            MessageBuilder::withPayload(serialize(Order::create('1', 'correct')))
                ->setContentType(MediaType::createApplicationXPHPSerialized())
                ->build()
        );
    }

    public function test_calling_if_media_type_is_incompatible_but_types_are_fine()
    {
        $referenceSearchService = InMemoryReferenceSearchService::createWith([
            AutoCollectionConversionService::REFERENCE_NAME => AutoCollectionConversionService::createWith([]),
        ]);
        $objectToInvoke         = new ExampleService();
        $interfaceToCall        = InterfaceToCall::create($objectToInvoke, 'receiveString');
        $methodInvocation       =
            WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                MethodInvoker::createWith(
                    $interfaceToCall,
                    $objectToInvoke,
                    [PayloadBuilder::create('id')],
                    $referenceSearchService
                ),
                $referenceSearchService
            );

        $result = $methodInvocation->executeEndpoint(
            MessageBuilder::withPayload('some')
                ->setContentType(MediaType::createApplicationXPHPSerialized())
                ->build()
        );

        $this->assertEquals('some', $result->getPayload());
    }

    public function test_calling_if_when_parameter_is_union_type_and_argument_compatible_with_second()
    {
        $referenceSearchService = InMemoryReferenceSearchService::createWith([
            AutoCollectionConversionService::REFERENCE_NAME => AutoCollectionConversionService::createWith([]),
        ]);
        $service                = new ServiceExpectingOneArgument();
        $interfaceToCall        = InterfaceToCall::create($service, 'withUnionParameter');
        $methodInvocation       =
            WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                MethodInvoker::createWith(
                    $interfaceToCall,
                    $service,
                    [
                        PayloadBuilder::create('value'),
                    ],
                    $referenceSearchService
                ),
                $referenceSearchService
            );

        $result = $methodInvocation->executeEndpoint(
            MessageBuilder::withPayload('some')
                ->setContentType(MediaType::createApplicationXPHPSerialized())
                ->build()
        );

        $this->assertEquals('some', $result->getPayload());
    }

    public function test_invoking_with_conversion_based_on_type_id_when_declaration_is_interface()
    {
        $referenceSearchService = InMemoryReferenceSearchService::createWith([
            AutoCollectionConversionService::REFERENCE_NAME => AutoCollectionConversionService::createWith([
                new StringToUuidClassConverter(),
            ]),
        ]);
        $interfaceToCall = InterfaceToCall::create(new ServiceExpectingOneArgument(), 'withInterface');

        $methodInvocation =
            WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                MethodInvoker::createWith($interfaceToCall, new ServiceExpectingOneArgument(), [
                    PayloadBuilder::create('value'),
                ], $referenceSearchService),
                $referenceSearchService
            );

        $data      = '893a660c-0208-4140-8be6-95fb2dcd2fdd';
        $replyMessage = $methodInvocation->executeEndpoint(
            MessageBuilder::withPayload($data)
                ->setHeader(MessageHeaders::TYPE_ID, Uuid::class)
                ->setContentType(MediaType::createApplicationXPHP())
                ->build()
        );

        $this->assertEquals(
            Uuid::fromString('893a660c-0208-4140-8be6-95fb2dcd2fdd'),
            $replyMessage->getPayload()
        );
    }

    public function test_invoking_with_conversion_and_union_type_resolving_type_from_type_header_with_different_media_type()
    {
        $referenceSearchService = InMemoryReferenceSearchService::createWith([
            AutoCollectionConversionService::REFERENCE_NAME => AutoCollectionConversionService::createWith([
                new JsonToArrayConverter(),
            ]),
        ]);
        $interfaceToCall = InterfaceToCall::create(new ServiceExpectingOneArgument(), 'withUnionParameterWithArray');

        $methodInvocation =
            WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                MethodInvoker::createWith($interfaceToCall, new ServiceExpectingOneArgument(), [
                    PayloadBuilder::create('value'),
                ], $referenceSearchService),
                $referenceSearchService
            );

        $data      = '["893a660c-0208-4140-8be6-95fb2dcd2fdd"]';
        $replyMessage = $methodInvocation->executeEndpoint(
            MessageBuilder::withPayload($data)
                ->setHeader(MessageHeaders::TYPE_ID, TypeDescriptor::ARRAY)
                ->setContentType(MediaType::createApplicationJson())
                ->build()
        );

        $this->assertEquals(
            ['893a660c-0208-4140-8be6-95fb2dcd2fdd'],
            $replyMessage->getPayload()
        );
    }

    public function test_throwing_exception_if_deserializing_to_union_without_type_header()
    {
        $referenceSearchService = InMemoryReferenceSearchService::createWith([
            AutoCollectionConversionService::REFERENCE_NAME => AutoCollectionConversionService::createWith([
                new JsonToArrayConverter(),
            ]),
        ]);
        $interfaceToCall = InterfaceToCall::create(new ServiceExpectingOneArgument(), 'withUnionParameterWithArray');

        $this->expectException(InvalidArgumentException::class);

        $methodInvocation =
            WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                MethodInvoker::createWith($interfaceToCall, new ServiceExpectingOneArgument(), [
                    PayloadBuilder::create('value'),
                ], $referenceSearchService),
                $referenceSearchService
            );

        $methodInvocation->executeEndpoint(
            MessageBuilder::withPayload('["893a660c-0208-4140-8be6-95fb2dcd2fdd"]')
                ->setContentType(MediaType::createApplicationJson())
                ->build()
        );
    }

    public function test_invoking_with_header_conversion_for_union_type_parameter()
    {
        $service = new ServiceExpectingOneArgument();
        $methodInvocation            = MethodInvoker::createWith(
            InterfaceToCall::create($service, 'withUnionParameterWithUuid'),
            $service,
            [
                HeaderBuilder::create('value', 'uuid'),
            ],
            InMemoryReferenceSearchService::createWith([
                AutoCollectionConversionService::REFERENCE_NAME => AutoCollectionConversionService::createWith([
                    new StringToUuidConverter(),
                ]),
            ])
        );

        $uuid = 'fd825894-907c-4c6c-88a9-ae1ecdf3d307';
        $replyMessage = $methodInvocation->executeEndpoint(
            MessageBuilder::withPayload('some')
                ->setHeader('uuid', $uuid)
                ->setContentType(MediaType::createTextPlain())
                ->build()
        );

        $this->assertEquals(
            Uuid::fromString($uuid),
            $replyMessage
        );
    }

    public function test_if_can_not_decide_return_type_make_use_resolved_from_return_value_for_array()
    {
        $referenceSearchService = InMemoryReferenceSearchService::createEmpty();
        $service                = new ServiceExpectingOneArgument();
        $interfaceToCall        = InterfaceToCall::create($service, 'withCollectionAndArrayReturnType');
        $methodInvocation       =
            WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                MethodInvoker::createWith(
                    $interfaceToCall,
                    $service,
                    [
                        PayloadBuilder::create('value'),
                    ],
                    $referenceSearchService
                ),
                $referenceSearchService
            );

        $replyMessage = $methodInvocation->executeEndpoint(MessageBuilder::withPayload(['test'])->build());

        $this->assertEquals(
            MediaType::createApplicationXPHPWithTypeParameter('array')->toString(),
            $replyMessage->getHeaders()->getContentType()->toString()
        );
    }

    public function test_if_can_decide_based_on_return_type_then_should_be_used_for_array()
    {
        $referenceSearchService = InMemoryReferenceSearchService::createEmpty();
        $service                = new ServiceExpectingOneArgument();
        $interfaceToCall        = InterfaceToCall::create($service, 'withCollectionAndArrayReturnType');
        $methodInvocation       =
            WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                MethodInvoker::createWith(
                    $interfaceToCall,
                    $service,
                    [
                        PayloadBuilder::create('value'),
                    ],
                    $referenceSearchService
                ),
                $referenceSearchService
            );

        $replyMessage = $methodInvocation->executeEndpoint(MessageBuilder::withPayload([new stdClass()])->build());

        $this->assertEquals(
            MediaType::createApplicationXPHPWithTypeParameter('array<stdClass>')->toString(),
            $replyMessage->getHeaders()->getContentType()->toString()
        );
    }

    public function test_given_return_type_is_union_then_should_decide_on_return_type_based_on_return_variable()
    {
        $referenceSearchService = InMemoryReferenceSearchService::createEmpty();
        $service                = new ServiceExpectingOneArgument();
        $interfaceToCall        = InterfaceToCall::create($service, 'withUnionReturnType');
        $methodInvocation       =
            WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                MethodInvoker::createWith(
                    $interfaceToCall,
                    $service,
                    [
                        PayloadBuilder::create('value'),
                    ],
                    $referenceSearchService
                ),
                $referenceSearchService
            );

        $replyMessage = $methodInvocation->executeEndpoint(MessageBuilder::withPayload(new stdClass())->build());

        $this->assertEquals(
            MediaType::createApplicationXPHPWithTypeParameter(stdClass::class)->toString(),
            $replyMessage->getHeaders()->getContentType()->toString()
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReferenceNotFoundException
     * @throws MessagingException
     */
    public function test_invoking_with_header_conversion()
    {
        $orderProcessor   = new OrderProcessor();
        $interfaceToCall = InterfaceToCall::create($orderProcessor, 'buyByName');

        $methodInvocation = MethodInvoker::createWith(
            $interfaceToCall,
            $orderProcessor,
            [
                HeaderBuilder::create('id', 'uuid'),
            ],
            InMemoryReferenceSearchService::createWith([
                AutoCollectionConversionService::REFERENCE_NAME => AutoCollectionConversionService::createWith([
                    new StringToUuidConverter(),
                ]),
            ])
        );

        $uuid = 'fd825894-907c-4c6c-88a9-ae1ecdf3d307';
        $replyMessage = $methodInvocation->executeEndpoint(
            MessageBuilder::withPayload('some')
                ->setHeader('uuid', $uuid)
                ->setContentType(MediaType::createTextPlain())
                ->build()
        );

        $this->assertEquals(
            OrderConfirmation::createFromUuid(Uuid::fromString($uuid)),
            $replyMessage
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReferenceNotFoundException
     * @throws MessagingException
     */
    public function test_invoking_with_converter_for_collection_if_types_are_compatible()
    {
        $service   = new OrderProcessor();
        $interfaceToCall = InterfaceToCall::create($service, 'buyMultiple');

        $methodInvocation = MethodInvoker::createWith(
            $interfaceToCall,
            $service,
            [
                PayloadBuilder::create('ids'),
            ],
            InMemoryReferenceSearchService::createWith([
                AutoCollectionConversionService::REFERENCE_NAME => AutoCollectionConversionService::createWith([
                    new StringToUuidConverter(),
                ]),
            ])
        );

        $replyMessage = $methodInvocation->executeEndpoint(
            MessageBuilder::withPayload(['fd825894-907c-4c6c-88a9-ae1ecdf3d307', 'fd825894-907c-4c6c-88a9-ae1ecdf3d308'])
                ->setContentType(MediaType::createApplicationXPHPWithTypeParameter('array<string>'))
                ->build()
        );

        $this->assertEquals(
            [OrderConfirmation::createFromUuid(Uuid::fromString('fd825894-907c-4c6c-88a9-ae1ecdf3d307')), OrderConfirmation::createFromUuid(Uuid::fromString('fd825894-907c-4c6c-88a9-ae1ecdf3d308'))],
            $replyMessage
        );
    }

    public function test_calling_interceptor_with_multiple_unordered_arguments()
    {
        $interceptingService1 = CallMultipleUnorderedArgumentsInvocationInterceptorExample::create();
        $interceptedService = StubCallSavingService::create();
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'callWithMultipleArguments'),
            $interceptedService,
            [
                PayloadBuilder::create('some'),
                HeaderBuilder::create('numbers', 'numbers'),
                HeaderBuilder::create('strings', 'strings'),
            ],
            InMemoryReferenceSearchService::createWith([
                CallMultipleUnorderedArgumentsInvocationInterceptorExample::class => $interceptingService1,
            ]),
            InMemoryChannelResolver::createEmpty(),
            [
                AroundInterceptorReference::createWithNoPointcut(CallMultipleUnorderedArgumentsInvocationInterceptorExample::class, InterfaceToCall::create(CallMultipleUnorderedArgumentsInvocationInterceptorExample::class, 'callMultipleUnorderedArgumentsInvocation')),
            ]
        );

        $message = MessageBuilder::withPayload(new stdClass())
            ->setHeader('numbers', [5, 1])
            ->setHeader('strings', ['string1', 'string2'])
            ->build();
        $methodInvocation->executeEndpoint($message);

        $this->assertTrue($interceptedService->wasCalled(), 'Intercepted Service was not called');
    }

    public function test_passing_through_message_when_calling_interceptor_without_method_invocation()
    {
        $interceptingService1 = CallWithPassThroughInterceptorExample::create();
        $interceptedService = StubCallSavingService::createWithReturnType('some');
        $interfaceToCall = InterfaceToCall::create($interceptedService, 'callWithReturn');

        $methodInvocation = MethodInvoker::createWith(
            $interfaceToCall,
            $interceptedService,
            [],
            InMemoryReferenceSearchService::createWith([
                CallWithPassThroughInterceptorExample::class => $interceptingService1,
            ]),
            InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut(CallWithPassThroughInterceptorExample::class, InterfaceToCall::create(CallWithPassThroughInterceptorExample::class, 'callWithPassThrough'))]
        );

        $this->assertEquals(
            'some',
            $methodInvocation->executeEndpoint(MessageBuilder::withPayload(new stdClass())->build())
        );
    }

    public function test_calling_interceptor_with_intercepted_object_instance()
    {
        $interceptingService1 = CallWithInterceptedObjectInterceptorExample::create();
        $interceptedService = StubCallSavingService::createWithReturnType('some');
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'callWithReturn'),
            $interceptedService,
            [],
            InMemoryReferenceSearchService::createWith([
                CallWithInterceptedObjectInterceptorExample::class => $interceptingService1,
            ]),
            InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut(CallWithInterceptedObjectInterceptorExample::class, InterfaceToCall::create(CallWithInterceptedObjectInterceptorExample::class, 'callWithInterceptedObject'))]
        );

        $this->assertEquals(
            'some',
            $methodInvocation->executeEndpoint(MessageBuilder::withPayload(new stdClass())->build())
        );
    }

    public function test_calling_interceptor_with_method_annotation()
    {
        $interceptingService1 = CallWithAnnotationFromMethodInterceptorExample::create();
        $interceptedService = StubCallSavingService::createWithReturnType('some');
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'methodWithAnnotation'),
            $interceptedService,
            [],
            InMemoryReferenceSearchService::createWith([
                CallWithAnnotationFromMethodInterceptorExample::class => $interceptingService1,
            ]),
            InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut(CallWithAnnotationFromMethodInterceptorExample::class, InterfaceToCall::create(CallWithAnnotationFromMethodInterceptorExample::class, 'callWithMethodAnnotation'))]
        );

        $requestMessage = MessageBuilder::withPayload('test')->build();
        $this->assertNull($methodInvocation->executeEndpoint($requestMessage));
    }

    public function test_calling_interceptor_with_endpoint_annotation()
    {
        $interceptedService = StubCallSavingService::createWithReturnType('some');
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'methodWithAnnotation'),
            $interceptedService,
            [],
            InMemoryReferenceSearchService::createEmpty(),
            InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithDirectObjectAndResolveConverters(InterfaceToCallRegistry::createEmpty(), CallWithStdClassInterceptorExample::create(), 'callWithStdClass', 0, '')],
            [
                new stdClass(),
            ]
        );

        $requestMessage = MessageBuilder::withPayload('test')->build();
        $this->assertNull($methodInvocation->executeEndpoint($requestMessage));
    }

    public function test_calling_interceptor_with_reference_search_service()
    {
        $interceptingService1 = CallWithReferenceSearchServiceExample::create();
        $interceptedService = StubCallSavingService::createWithReturnType('some');
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'methodWithAnnotation'),
            $interceptedService,
            [],
            InMemoryReferenceSearchService::createWith([
                CallWithReferenceSearchServiceExample::class => $interceptingService1,
            ]),
            InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut(CallWithReferenceSearchServiceExample::class, InterfaceToCall::create(CallWithReferenceSearchServiceExample::class, 'call'))]
        );

        $requestMessage = MessageBuilder::withPayload('test')->build();
        $this->assertNull($methodInvocation->executeEndpoint($requestMessage));
    }

    public function test_throwing_exception_if_registering_around_method_interceptor_with_return_value_but_without_method_invocation()
    {
        $interceptingService1 = CalculatingService::create(0);
        $interceptedService = StubCallSavingService::createWithReturnType('some');
        $interfaceToCall = InterfaceToCall::create($interceptedService, 'callWithReturn');

        $this->expectException(InvalidArgumentException::class);

        MethodInvoker::createWith(
            $interfaceToCall,
            $interceptedService,
            [],
            InMemoryReferenceSearchService::createWith([
                CalculatingService::class => $interceptingService1,
            ]),
            InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut(CalculatingService::class, InterfaceToCall::create(CalculatingService::class, 'sum'))]
        );
    }

    public function test_passing_endpoint_annotation()
    {
        $interceptingService1 = CallWithStdClassInterceptorExample::create();
        $interceptedService = StubCallSavingService::createWithReturnType('some');
        $interfaceToCall = InterfaceToCall::create($interceptedService, 'methodWithAnnotation');

        $methodInvocation = MethodInvoker::createWith(
            $interfaceToCall,
            $interceptedService,
            [],
            InMemoryReferenceSearchService::createWith([
                CallWithStdClassInterceptorExample::class => $interceptingService1,
            ]),
            InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut(CallWithStdClassInterceptorExample::class, InterfaceToCall::create(CallWithStdClassInterceptorExample::class, 'callWithStdClass'))],
            [new stdClass()]
        );

        $requestMessage = MessageBuilder::withPayload('test')->build();
        $this->assertNull($methodInvocation->executeEndpoint($requestMessage));
    }

    public function test_passing_payload_if_compatible()
    {
        $interceptingService1 = CallWithStdClassInterceptorExample::create();
        $interceptedService = StubCallSavingService::createWithReturnType('some');
        $interfaceToCall = InterfaceToCall::create($interceptedService, 'callWithMessage');

        $methodInvocation = MethodInvoker::createWith(
            $interfaceToCall,
            $interceptedService,
            [],
            InMemoryReferenceSearchService::createWith([
                CallWithStdClassInterceptorExample::class => $interceptingService1,
            ]),
            InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut(CallWithStdClassInterceptorExample::class, InterfaceToCall::create(CallWithStdClassInterceptorExample::class, 'callWithStdClass'))],
            []
        );

        $requestMessage = MessageBuilder::withPayload(new stdClass())
            ->setContentType(MediaType::createApplicationXPHPWithTypeParameter(stdClass::class))
            ->build();
        $this->assertNull($methodInvocation->executeEndpoint($requestMessage));
    }
}
