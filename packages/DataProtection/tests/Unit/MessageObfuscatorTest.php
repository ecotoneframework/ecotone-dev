<?php

namespace Test\Ecotone\DataProtection\Unit;

use Defuse\Crypto\Key;
use Ecotone\DataProtection\Obfuscator\MessageObfuscator;
use Ecotone\DataProtection\Obfuscator\Obfuscator;
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

    protected function setUp(): void
    {
        $this->message = MessageBuilder::withPayload(json_encode([
            'foo' => 'value',
            'bar' => 'value',
        ], JSON_THROW_ON_ERROR))
            ->setHeader(MessageHeaders::TYPE_ID, ObfuscatedMessage::class)
            ->build()
        ;
    }

    public function test_obfuscate_message_fully(): void
    {
        $obfuscator = new Obfuscator([], ['foo', 'bar'], Key::createNewRandomKey());
        $messageObfuscator = new MessageObfuscator([ObfuscatedMessage::class => $obfuscator]);

        $encryptedPayload = $messageObfuscator->encrypt($this->message);

        $encryptedMessage = MessageBuilder::fromMessage($this->message)
            ->setPayload($encryptedPayload)
            ->build()
        ;

        $decryptedPayload = $messageObfuscator->decrypt($encryptedMessage);

        $payload = json_decode($encryptedPayload, true, 512, JSON_THROW_ON_ERROR);

        self::assertNotEquals('value', $payload['foo']);
        self::assertNotEquals('value', $payload['bar']);
        self::assertNotEquals($this->message->getPayload(), $encryptedPayload);
        self::assertEquals($this->message->getPayload(), $decryptedPayload);
    }

    public function test_obfuscate_message_partially(): void
    {
        $obfuscator = new Obfuscator(['foo', 'non-existing-argument'], ['foo', 'bar'], Key::createNewRandomKey());
        $messageObfuscator = new MessageObfuscator([ObfuscatedMessage::class => $obfuscator]);

        $encryptedPayload = $messageObfuscator->encrypt($this->message);

        $encryptedMessage = MessageBuilder::fromMessage($this->message)
            ->setPayload($encryptedPayload)
            ->build()
        ;

        $decryptedPayload = $messageObfuscator->decrypt($encryptedMessage);

        $payload = json_decode($encryptedPayload, true, 512, JSON_THROW_ON_ERROR);

        self::assertNotEquals('value', $payload['foo']);
        self::assertEquals('value', $payload['bar']);
        self::assertNotEquals($this->message->getPayload(), $encryptedPayload);
        self::assertEquals($this->message->getPayload(), $decryptedPayload);
        self::assertArrayNotHasKey('non-existing-argument', $payload);
        self::assertArrayNotHasKey('non-existing-argument', json_decode($decryptedPayload, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_dont_obfuscate_unsupported_message(): void
    {
        $obfuscator = new Obfuscator(['foo', 'bar'], ['foo', 'bar'], Key::createNewRandomKey());
        $messageObfuscator = new MessageObfuscator([\stdClass::class => $obfuscator]);

        $encryptedPayload = $messageObfuscator->encrypt($this->message);

        $encryptedMessage = MessageBuilder::fromMessage($this->message)
            ->setPayload($encryptedPayload)
            ->build()
        ;

        $decryptedPayload = $messageObfuscator->decrypt($encryptedMessage);

        $payload = json_decode($encryptedPayload, true, 512, JSON_THROW_ON_ERROR);

        self::assertEquals('value', $payload['foo']);
        self::assertEquals('value', $payload['bar']);
        self::assertEquals($this->message->getPayload(), $encryptedPayload);
        self::assertEquals($this->message->getPayload(), $decryptedPayload);
        self::assertArrayNotHasKey('non-existing-argument', $payload);
        self::assertArrayNotHasKey('non-existing-argument', json_decode($decryptedPayload, true, 512, JSON_THROW_ON_ERROR));
    }
}
