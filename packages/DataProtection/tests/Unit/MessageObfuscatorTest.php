<?php

namespace Test\Ecotone\DataProtection\Unit;

use Ecotone\DataProtection\Obfuscator\MessageObfuscator;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\DataProtection\Fixture\ObfuscatedMessage;

/**
 * @internal
 */
class MessageObfuscatorTest extends TestCase
{
    private Message $message;
    private Message $messageWithoutTypeId;

    protected function setUp(): void
    {
        $this->message = MessageBuilder::withPayload(json_encode([
            'foo' => 'value',
            'bar' => 'value',
        ], JSON_THROW_ON_ERROR))
            ->setHeader(MessageHeaders::TYPE_ID, ObfuscatedMessage::class)
            ->setHeader('foo', 'bar')
            ->build()
        ;

        $this->messageWithoutTypeId = MessageBuilder::withPayload(json_encode([
            'foo' => 'value',
            'bar' => 'value',
        ], JSON_THROW_ON_ERROR))
            ->setHeader('foo', 'bar')
            ->build()
        ;
    }

    public function test_obfuscate_only_supported_message(): void
    {
        $messageObfuscator = new MessageObfuscator();

        self::assertSame($this->message, $messageObfuscator->encrypt($this->message));
        self::assertSame($this->messageWithoutTypeId, $messageObfuscator->encrypt($this->messageWithoutTypeId));

        self::assertSame($this->message, $messageObfuscator->decrypt($this->message));
        self::assertSame($this->messageWithoutTypeId, $messageObfuscator->decrypt($this->messageWithoutTypeId));
    }
}
