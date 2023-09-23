<?php

namespace Test;

use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\SymfonyBundle\Messenger\MetadataStamp;
use Ecotone\SymfonyBundle\Messenger\SymfonyMessageConverter;
use Ecotone\SymfonyBundle\Messenger\SymfonyMessengerMessageChannel;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;

class SymfonyMessengerMessageChannelUnitTest extends TestCase
{
    private SymfonyMessageConverter $messageConverter;
    private Envelope $envelope;
    public function setUp(): void
    {
        $this->messageConverter = new SymfonyMessageConverter(
            $this->createMock(HeaderMapper::class),
            'mode',
            $this->createMock(ConversionService::class)
        );
        $this->envelope = new Envelope(
            $this->createMock(stdClass::class),
            [
                new MetadataStamp([]),
            ],
        );
    }

    public function testSymfonyEnvelopeReturnsArray(): void
    {
        $transport = $this->createMock(DoctrineTransport::class);
        $transport->method('get')->willReturn([$this->envelope]);
        $transport->method('getMessageCount')->willReturn(1);
        $messageChannel = new SymfonyMessengerMessageChannel($transport, $this->messageConverter);

        $this->assertInstanceOf(Message::class, $messageChannel->receive());
    }

    public function testSymfonyEnvelopeReturnsArrayFailsBecauseOfTooManyMessages(): void
    {
        $transport = $this->createMock(DoctrineTransport::class);
        $transport->method('get')->willReturn([$this->envelope, $this->envelope]);
        $transport->method('getMessageCount')->willReturn(2);
        $messageChannel = new SymfonyMessengerMessageChannel($transport, $this->messageConverter);

        $this->expectException(InvalidArgumentException::class);
        $messageChannel->receive();
    }

    private function singleMessageGenerator(): iterable
    {
        yield $this->envelope;
    }

    public function testSymfonyEnvelopeReturnsGenerator(): void
    {
        $transport = $this->createMock(AmqpTransport::class);
        $transport->method('get')->willReturn($this->singleMessageGenerator());
        $transport->method('getMessageCount')->willReturn(1);
        $messageChannel = new SymfonyMessengerMessageChannel($transport, $this->messageConverter);

        $this->assertInstanceOf(Message::class, $messageChannel->receive());
    }

//    private function multiMessageGenerator(): iterable
//    {
//        yield $this->envelope;
//    }
//    public function testSymfonyEnvelopeReturnsGeneratorFailsBecauseOfTooManyMessages(): void
//    {
//        $transport = $this->createMock(AmqpTransport::class);
//        $transport->method('get')->willReturn($this->multiMessageGenerator());
//        $transport->method('getMessageCount')->willReturn(2);
//        $messageChannel = new SymfonyMessengerMessageChannel($transport, $this->messageConverter);
//
//        $this->expectException(InvalidArgumentException::class);
//        $messageChannel->receive();
//    }
}
