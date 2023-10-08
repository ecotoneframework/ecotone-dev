<?php

namespace Test\Ecotone\Messaging\Unit\Handler\Processor;

use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\AllHeadersBuilder;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\Service\CallableService;

/**
 * Class AllHeadersBuilderTest
 * @package Test\Ecotone\Messaging\Unit\Handler\Processor
 * @author  Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * @internal
 */
class AllHeadersBuilderTest extends TestCase
{
    public function test_retrieving_all_headers()
    {
        $result = AllHeadersBuilder::createWith('some')->build(
            InMemoryReferenceSearchService::createEmpty(),
            InterfaceToCall::create(CallableService::class, 'wasCalled'),
            InterfaceParameter::createNullable('some', TypeDescriptor::createStringType()),
        )->getArgumentFrom(
            MessageBuilder::withPayload('some')
                ->setHeader('someId', 123)
                ->build(),
        );
        unset($result[MessageHeaders::MESSAGE_ID]);
        unset($result[MessageHeaders::MESSAGE_CORRELATION_ID]);
        unset($result[MessageHeaders::TIMESTAMP]);

        $this->assertEquals(
            [
                'someId' => 123,
            ],
            $result
        );
    }
}
