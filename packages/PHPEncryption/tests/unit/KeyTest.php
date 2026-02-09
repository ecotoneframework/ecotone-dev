<?php

namespace Test\Ecotone\PHPEncryption;

use Ecotone\PHPEncryption\Core;
use Ecotone\PHPEncryption\Key;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @internal
 */
class KeyTest extends TestCase
{
    public function test_create_new_random_key(): void
    {
        $key = Key::createNewRandomKey();
        $this->assertSame(32, Core::strlen($key->getRawBytes()));
    }

    public function test_save_and_load_key(): void
    {
        $key1 = Key::createNewRandomKey();
        $str  = $key1->saveToAsciiSafeString();
        $key2 = Key::loadFromAsciiSafeString($str);
        $this->assertSame($key1->getRawBytes(), $key2->getRawBytes());
    }

    public function test_incorrect_header(): void
    {
        $key    = Key::createNewRandomKey();
        $str    = $key->saveToAsciiSafeString();
        $str[0] = 'f';
        $this->expectException(\Ecotone\PHPEncryption\Exception\BadFormatException::class);
        $this->expectExceptionMessage('Invalid header.');
        Key::loadFromAsciiSafeString($str);
    }
}
