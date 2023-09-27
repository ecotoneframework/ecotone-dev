<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\Processor;

use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Support\MessageBuilder;
use ReflectionException;
use Test\Ecotone\Messaging\Fixture\Service\CallableService;
use Test\Ecotone\Messaging\Unit\MessagingTest;

/**
 * Class PayloadBuilder
 * @package Test\Ecotone\Messaging\Fixture\Handler\Processor
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * @internal
 */
class PayloadBuilderTest extends MessagingTest
{
    /**
     * @throws ReflectionException
     * @throws \Ecotone\Messaging\MessagingException
     */
    public function test_creating_payload_converter()
    {
        $converter = PayloadBuilder::create('some');
        $converter = $converter->build(InMemoryReferenceSearchService::createEmpty(),
                InterfaceToCall::create(CallableService::class, 'wasCalled'),
                InterfaceParameter::createNullable('x', TypeDescriptor::createWithDocBlock('string', '')),
        );

        $payload = 'rabbit';
        $this->assertEquals(
            $payload,
            $converter->getArgumentFrom(
                MessageBuilder::withPayload($payload)->build(),
            )
        );
    }
}
