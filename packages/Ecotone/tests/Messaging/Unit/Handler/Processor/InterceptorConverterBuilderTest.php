<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\Processor;

use Doctrine\Common\Annotations\AnnotationException;
use Ecotone\Messaging\Attribute\ClassReference;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\MessageHandlingException;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\InterceptorConverterBuilder;
use Ecotone\Messaging\Handler\TypeDefinitionException;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\MessagingException;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Messaging\Transaction\Transactional;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithUnorderedClassInvocationInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\TransactionalInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Service\ServiceWithoutReturnValue;

/**
 * Class InterceptorConverterBuilderTest
 * @package Test\Ecotone\Messaging\Unit\Handler\Processor
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * @internal
 */
class InterceptorConverterBuilderTest extends TestCase
{
    /**
     * @throws AnnotationException
     * @throws ReflectionException
     * @throws TypeDefinitionException
     * @throws MessagingException
     * @throws InvalidArgumentException
     */
    public function test_retrieving_intercepted_method_annotation()
    {
        $interfaceToCall = InterfaceToCall::create(TransactionalInterceptorExample::class, 'doAction');
        $parameter = InterfaceParameter::createNotNullable('some', TypeDescriptor::create(Transactional::class));
        $converter = InterceptorConverterBuilder::create($parameter, $interfaceToCall, []);
        $converter = $converter->build(InMemoryReferenceSearchService::createEmpty());

        $methodAnnotation = Transactional::createWith(['reference2']);

        $this->assertEquals(
            $methodAnnotation,
            $converter->getArgumentFrom(
                MessageBuilder::withPayload('a')->setHeader('token', 123)->build(),
            )
        );
    }

    /**
     * @throws AnnotationException
     * @throws ReflectionException
     * @throws TypeDefinitionException
     * @throws MessagingException
     * @throws InvalidArgumentException
     */
    public function test_retrieving_intercepted_class_annotation()
    {
        $interfaceToCall = InterfaceToCall::create(CallWithUnorderedClassInvocationInterceptorExample::class, 'callWithUnorderedClassInvocation');
        $parameter = InterfaceParameter::createNotNullable('some', TypeDescriptor::create(ClassReference::class));
        $converter = InterceptorConverterBuilder::create($parameter, $interfaceToCall, []);
        $converter = $converter->build(InMemoryReferenceSearchService::createEmpty());

        $classAnnotation = new ClassReference('callWithUnordered');

        $this->assertEquals(
            $classAnnotation,
            $converter->getArgumentFrom(
                MessageBuilder::withPayload('a')->setHeader('token', 123)->build(),
            )
        );
    }

    /**
     * @throws AnnotationException
     * @throws ReflectionException
     * @throws TypeDefinitionException
     * @throws MessagingException
     * @throws InvalidArgumentException
     */
    public function test_retrieving_intercepted_endpoint_annotation()
    {
        $interfaceToCall = InterfaceToCall::create(TransactionalInterceptorExample::class, 'doAction');

        $endpointAnnotation = Transactional::createWith(['reference10000']);
        $parameter = InterfaceParameter::createNotNullable('some', TypeDescriptor::create(Transactional::class));
        $converter = InterceptorConverterBuilder::create($parameter, $interfaceToCall, [
            $endpointAnnotation,
        ]);
        $converter = $converter->build(InMemoryReferenceSearchService::createEmpty());

        $this->assertEquals(
            $endpointAnnotation,
            $converter->getArgumentFrom(
                MessageBuilder::withPayload('a')->setHeader('token', 123)->build(),
            )
        );
    }

    /**
     * @throws AnnotationException
     * @throws ReflectionException
     * @throws TypeDefinitionException
     * @throws MessagingException
     * @throws InvalidArgumentException
     */
    public function test_returning_null_if_no_annotation_found()
    {
        $converter = InterceptorConverterBuilder::create(InterfaceParameter::createNullable('transactional', TypeDescriptor::createWithDocBlock(Transactional::class, '')), InterfaceToCall::create(ServiceWithoutReturnValue::class, 'wasCalled'), [])
                        ->build(InMemoryReferenceSearchService::createEmpty());

        $this->assertNull(
            $converter->getArgumentFrom(
                MessageBuilder::withPayload('a')->setHeader('token', 123)->build(),
            )
        );
    }

    public function test_throwing_exception_if_require_missing_annotation()
    {
        $converter = InterceptorConverterBuilder::create(InterfaceParameter::createNotNullable('transactional', TypeDescriptor::createWithDocBlock(Transactional::class, '')), InterfaceToCall::create(ServiceWithoutReturnValue::class, 'wasCalled'), [])
            ->build(InMemoryReferenceSearchService::createEmpty());

        $this->expectException(MessageHandlingException::class);

        $converter->getArgumentFrom(
            MessageBuilder::withPayload('a')->setHeader('token', 123)->build(),
        );
    }
}
