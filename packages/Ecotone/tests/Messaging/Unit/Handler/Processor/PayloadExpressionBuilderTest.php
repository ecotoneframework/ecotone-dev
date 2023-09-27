<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\Processor;

use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadExpressionBuilder;
use Ecotone\Messaging\Handler\SymfonyExpressionEvaluationAdapter;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Support\MessageBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use Test\Ecotone\Messaging\Fixture\Service\CalculatingService;
use Test\Ecotone\Messaging\Fixture\Service\CallableService;

/**
 * Class ExpressionBuilderTest
 * @package Test\Ecotone\Messaging\Unit\Handler\Processor
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * @internal
 */
class PayloadExpressionBuilderTest extends TestCase
{
    /**
     * @throws \Doctrine\Common\Annotations\AnnotationException
     * @throws ReflectionException
     * @throws \Ecotone\Messaging\Handler\TypeDefinitionException
     * @throws \Ecotone\Messaging\MessagingException
     * @throws \Ecotone\Messaging\Support\InvalidArgumentException
     */
    public function test_creating_payload_expression()
    {
        $converter = PayloadExpressionBuilder::create('x', 'value ~ 1');
        $converter = $converter->build(InMemoryReferenceSearchService::createWith([
            ExpressionEvaluationService::REFERENCE => SymfonyExpressionEvaluationAdapter::create(),
        ]),
                InterfaceToCall::create(CallableService::class, 'wasCalled'),
                InterfaceParameter::createNullable('x', TypeDescriptor::createWithDocBlock('string', '')),
        );

        $this->assertEquals(
            '1001',
            $converter->getArgumentFrom(
                MessageBuilder::withPayload('100')
                    ->build(),
            )
        );
    }

    /**
     * @throws \Ecotone\Messaging\Handler\TypeDefinitionException
     * @throws \Ecotone\Messaging\MessagingException
     */
    public function test_using_reference_service_in_expression()
    {
        $converter = PayloadExpressionBuilder::create('x', "reference('calculatingService').sum(value)");

        $converter = $converter->build(InMemoryReferenceSearchService::createWith([
            ExpressionEvaluationService::REFERENCE => SymfonyExpressionEvaluationAdapter::create(),
            'calculatingService' => CalculatingService::create(1),
        ]),
                InterfaceToCall::create(CallableService::class, 'wasCalled'),
                InterfaceParameter::createNullable('x', TypeDescriptor::create('string')),
        );

        $this->assertEquals(
            101,
            $converter->getArgumentFrom(
                MessageBuilder::withPayload(100)
                    ->build(),
            )
        );
    }
}
