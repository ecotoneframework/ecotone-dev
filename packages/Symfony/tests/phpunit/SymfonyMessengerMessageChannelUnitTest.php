<?php

namespace Test;

use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\SymfonyBundle\Messenger\MetadataStamp;
use Ecotone\SymfonyBundle\Messenger\SymfonyMessageConverter;
use Ecotone\SymfonyBundle\Messenger\SymfonyMessengerMessageChannel;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class SymfonyMessengerMessageChannelUnitTest extends TestCase
{
    private SymfonyMessageConverter $messageConverter;
    private Envelope $envelope;

    #[After]
    public function internalDisableErrorHandler(): void
    {
        restore_exception_handler();
    }

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

    public function test_symfony_envelope_returns_array(): void
    {
        $transport = $this->createMock(DoctrineTransport::class);
        $transport->method('get')->willReturn([$this->envelope]);
        $transport->method('getMessageCount')->willReturn(1);
        $messageChannel = new SymfonyMessengerMessageChannel($transport, $this->messageConverter);

        $this->assertInstanceOf(Message::class, $messageChannel->receive());
    }

    private function singleMessageGenerator(): iterable
    {
        yield $this->envelope;
    }

    public function test_symfony_envelope_returns_generator(): void
    {
        $transport = $this->createMock(AmqpTransport::class);
        $transport->method('get')->willReturn($this->singleMessageGenerator());
        $transport->method('getMessageCount')->willReturn(1);
        $messageChannel = new SymfonyMessengerMessageChannel($transport, $this->messageConverter);

        $this->assertInstanceOf(Message::class, $messageChannel->receive());
    }
}
