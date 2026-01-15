<?php

namespace Test\Ecotone\DataProtection\Unit;

use Defuse\Crypto\Key;
use Ecotone\DataProtection\Obfuscator\Obfuscator;
use PHPUnit\Framework\TestCase;

class ObfuscatorTest extends TestCase
{
    private string $payload;

    protected function setUp(): void
    {
        $this->payload = json_encode([
            'foo' => 'value',
            'bar' => 'value',
        ], JSON_THROW_ON_ERROR);
    }

    public function test_obfuscate_payload_fully(): void
    {
        $obfuscator = new Obfuscator([], ['foo', 'bar'], Key::createNewRandomKey());

        $encryptedPayload = $obfuscator->encrypt($this->payload);
        $decryptedPayload = $obfuscator->decrypt($encryptedPayload);

        $payload = json_decode($encryptedPayload, true, 512, JSON_THROW_ON_ERROR);

        self::assertNotEquals('value', $payload['foo']);
        self::assertNotEquals('value', $payload['bar']);
        self::assertNotEquals($this->payload, $encryptedPayload);
        self::assertEquals($this->payload, $decryptedPayload);
    }

    public function test_obfuscate_payload_partially(): void
    {
        $obfuscator = new Obfuscator(['foo', 'non-existing-argument'], ['foo', 'bar'], Key::createNewRandomKey());

        $encryptedPayload = $obfuscator->encrypt($this->payload);
        $decryptedPayload = $obfuscator->decrypt($encryptedPayload);

        $payload = json_decode($encryptedPayload, true, 512, JSON_THROW_ON_ERROR);

        self::assertNotEquals('value', $payload['foo']);
        self::assertEquals('value', $payload['bar']);
        self::assertNotEquals($this->payload, $encryptedPayload);
        self::assertEquals($this->payload, $decryptedPayload);
        self::assertArrayNotHasKey('non-existing-argument', $payload);
        self::assertArrayNotHasKey('non-existing-argument', json_decode($decryptedPayload, true, 512, JSON_THROW_ON_ERROR));
    }
}
