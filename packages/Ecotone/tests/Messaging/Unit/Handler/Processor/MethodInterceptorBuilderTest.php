<?php

namespace Test\Ecotone\Messaging\Unit\Handler\Processor;

use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\AttributeBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ValueBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Test\ComponentTestBuilder;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\Annotation\Interceptor\InterceptorWithMultipleOptionalAttributes;
use Test\Ecotone\Messaging\Fixture\Annotation\Interceptor\ResolvedPointcut\AroundInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Annotation\Interceptor\ResolvedPointcut\AttributeOne;
use Test\Ecotone\Messaging\Fixture\Behat\Calculating\AfterMultiplyCalculation;
use Test\Ecotone\Messaging\Fixture\Behat\Calculating\BeforeMultiplyCalculation;
use Test\Ecotone\Messaging\Fixture\Behat\Calculating\Calculator;
use Test\Ecotone\Messaging\Fixture\Behat\Calculating\CalculatorInterceptor;
use Test\Ecotone\Messaging\Fixture\Behat\Calculating\PowerCalculation;

/**
 * Class MethodInterceptorTest
 * @package Test\Ecotone\Messaging\Unit\Handler\Processor
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 *
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class MethodInterceptorBuilderTest extends TestCase
{
    public function test_resolving_pointcut_automatically()
    {
        $this->assertEquals(
            MethodInterceptorBuilder::create(
                Reference::to(AroundInterceptorExample::class),
                InterfaceToCall::create(AroundInterceptorExample::class, 'withNonAnnotationClass'),
                1,
                '(' . AttributeOne::class . ')'
            ),
            MethodInterceptorBuilder::create(
                Reference::to(AroundInterceptorExample::class),
                InterfaceToCall::create(AroundInterceptorExample::class, 'withNonAnnotationClass'),
                1,
                ''
            )
        );
    }
}
