<?php

namespace Test\Ecotone\DataProtection\Unit\Encryption;

use Ecotone\DataProtection\Encryption\Core;
use Ecotone\DataProtection\Encryption\Key;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class KeyTest extends TestCase
{
    public function test_create_new_random_key(): void
    {
        $key = Key::createNewRandomKey();
        self::assertSame(32, Core::strlen($key->getRawBytes()));
    }

    public function test_save_and_load_key(): void
    {
        $key1 = Key::createNewRandomKey();
        $str  = $key1->saveToAsciiSafeString();
        $key2 = Key::loadFromAsciiSafeString($str);
        self::assertSame($key1->getRawBytes(), $key2->getRawBytes());
    }

    public function test_incorrect_header(): void
    {
        $key    = Key::createNewRandomKey();
        $str    = $key->saveToAsciiSafeString();
        $str[0] = 'f';
        $this->expectException(\Ecotone\DataProtection\Encryption\Exception\BadFormatException::class);
        $this->expectExceptionMessage('Invalid header.');
        Key::loadFromAsciiSafeString($str);
    }
}
