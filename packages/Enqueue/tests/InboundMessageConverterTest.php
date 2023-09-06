<?php

declare(strict_types=1);

namespace Test\Ecotone;

use Ecotone\Enqueue\EnqueueAcknowledgementCallback;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Conversion\InMemoryConversionService;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\MessageHeaders;
use Enqueue\Null\NullConsumer;
use Enqueue\Null\NullMessage;
use Enqueue\Null\NullQueue;
use PHPUnit\Framework\TestCase;

final class InboundMessageConverterTest extends TestCase
{
    public function test_it_will_map_automatically_core_headers(): void
    {
        $inboundMessageConverter = new InboundMessageConverter(
            "some",
            EnqueueAcknowledgementCallback::AUTO_ACK,
            DefaultHeaderMapper::createNoMapping(),
            'ack'
        );

        $message = $inboundMessageConverter->toMessage(
            new NullMessage(
                properties: [
                    MessageHeaders::MESSAGE_ID => 123,
                    MessageHeaders::TIMESTAMP => 123000,
                    MessageHeaders::MESSAGE_CORRELATION_ID => 1234
                ]
            ),
            new NullConsumer(new NullQueue("some")),
            InMemoryConversionService::createWithoutConversion()
        )
        ->build();

        $this->assertEquals(123, $message->getHeaders()->get(MessageHeaders::MESSAGE_ID));
        $this->assertEquals(123000, $message->getHeaders()->get(MessageHeaders::TIMESTAMP));
        $this->assertEquals(1234, $message->getHeaders()->get(MessageHeaders::MESSAGE_CORRELATION_ID));
    }
}