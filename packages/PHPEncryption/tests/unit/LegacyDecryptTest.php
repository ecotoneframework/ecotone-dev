<?php

namespace Test\Ecotone\PHPEncryption;

use Ecotone\PHPEncryption\Core;
use Ecotone\PHPEncryption\Crypto;
use Ecotone\PHPEncryption\Encoding;
use Ecotone\PHPEncryption\Exception\WrongKeyOrModifiedCiphertextException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @internal
 */
class LegacyDecryptTest extends TestCase
{
    public function test_decrypt_legacy_ciphertext(): void
    {
        $cipher = Encoding::hexToBin(
            'cfdad83ebd506d2c9ada8d48030d0bca' .
            '2ff94760e6d39c186adb1290d6c47e35' .
            '821e262673c5631c42ebbaf70774d6ef' .
            '29aa5eee0e412d646ae380e08189c85d' .
            '024b5e2009106870f1db25d8b85fd01f'
        );

        $plain = Crypto::legacyDecrypt(
            $cipher,
            "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F"
        );

        $this->assertSame($plain, 'This is a test message');
    }

    public function test_decrypt_legacy_ciphertext_wrong_key(): void
    {
        $this->expectException(WrongKeyOrModifiedCiphertextException::class);

        Crypto::legacyDecrypt(
            Encoding::hexToBin(
                'cfdad83ebd506d2c9ada8d48030d0bca' .
                '2ff94760e6d39c186adb1290d6c47e35' .
                '821e262673c5631c42ebbaf70774d6ef' .
                '29aa5eee0e412d646ae380e08189c85d' .
                '024b5e2009106870f1db25d8b85fd01f'
            ),
            "\x01\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F"
            // ^- I changed that byte
        );
    }

    public function test_legacy_decrypt_too_short(): void
    {
        $too_short = str_repeat('a', Core::LEGACY_MAC_BYTE_SIZE);
        $this->expectException(WrongKeyOrModifiedCiphertextException::class);
        $this->expectExceptionMessage('short');
        Crypto::legacyDecrypt($too_short, '0123456789ABCDEF');
    }

}
