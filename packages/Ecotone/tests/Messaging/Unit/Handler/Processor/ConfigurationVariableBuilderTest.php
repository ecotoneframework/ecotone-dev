<?php

namespace Test\Ecotone\Messaging\Unit\Handler\Processor;

use Ecotone\Messaging\Config\Container\BoundParameterConverter;
use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ConfigurationVariableBuilder;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Test\ComponentTestBuilder;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\Service\CallableService;
use Test\Ecotone\Messaging\Fixture\Service\ServiceExpectingOneArgument;

/**
 * @internal
 */
class ConfigurationVariableBuilderTest extends TestCase
{
    public function test_retrieving_from_configuration()
    {
        $interfaceParameter    = InterfaceParameter::createNotNullable('johny', TypeDescriptor::createIntegerType());
        $configurationVariable = new BoundParameterConverter(
            ConfigurationVariableBuilder::createFrom('name', $interfaceParameter),
            InterfaceToCall::create(CallableService::class, 'wasCalled'),
            $interfaceParameter,
        );


        $this->assertEquals(
            100,
            ComponentTestBuilder::create()
                ->withConfiguration('name', 100)
                ->build($configurationVariable)
                ->getArgumentFrom(MessageBuilder::withPayload('some')->build())
        );
    }

    public function test_retrieving_from_configuration_using_parameter_name()
    {
        $interfaceParameter    = InterfaceParameter::createNotNullable('name', TypeDescriptor::createIntegerType());
        $configurationVariable = new BoundParameterConverter(
            ConfigurationVariableBuilder::createFrom(null, $interfaceParameter),
            InterfaceToCall::create(CallableService::class, 'wasCalled'),
            $interfaceParameter,
        );


        $this->assertEquals(
            100,
            ComponentTestBuilder::create()
                ->withConfiguration('name', 100)
                ->build($configurationVariable)
                ->getArgumentFrom(MessageBuilder::withPayload('some')->build())
        );
    }

    public function test_passing_null_when_configuration_variable_missing_but_null_is_possible()
    {
        $interfaceParameter    = InterfaceParameter::createNullable('name', TypeDescriptor::createIntegerType());
        $configurationVariable = new BoundParameterConverter(
            ConfigurationVariableBuilder::createFrom('name', $interfaceParameter),
            InterfaceToCall::create(CallableService::class, 'wasCalled'),
            $interfaceParameter,
        );


        $this->assertEquals(
            100,
            ComponentTestBuilder::create()
                ->build($configurationVariable)
                ->getArgumentFrom(MessageBuilder::withPayload('some')->build())
        );
    }

    public function test_passing_default_when_configuration_variable_missing_but_default_is_provided()
    {
        $defaultValue                     = 100;
        $interfaceParameter    = InterfaceParameter::create('name', TypeDescriptor::createIntegerType(), false, true, $defaultValue, false, []);
        $configurationVariable = new BoundParameterConverter(
            ConfigurationVariableBuilder::createFrom('name', $interfaceParameter),
            InterfaceToCall::create(CallableService::class, 'wasCalled'),
            $interfaceParameter,
        );


        $this->assertEquals(
            100,
            ComponentTestBuilder::create()
                ->build($configurationVariable)
                ->getArgumentFrom(MessageBuilder::withPayload('some')->build())
        );
    }

    public function test_throwing_exception_if_missing_configuration_variable()
    {
        $interfaceToCall = InterfaceToCall::create(ServiceExpectingOneArgument::class, 'withReturnValue');
        $interfaceParameter    = $interfaceToCall->getInterfaceParameters()[0];
        $configurationVariable = new BoundParameterConverter(
            ConfigurationVariableBuilder::createFrom('name', $interfaceParameter),
            $interfaceToCall,
            $interfaceParameter,
        );

        $this->expectException(InvalidArgumentException::class);

        ComponentTestBuilder::create()
            ->build($configurationVariable);
    }
}
