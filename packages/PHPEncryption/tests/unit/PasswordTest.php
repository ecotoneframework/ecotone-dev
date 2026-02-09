<?php

namespace Test\Ecotone\PHPEncryption;

use Ecotone\PHPEncryption\Exception\BadFormatException;
use Ecotone\PHPEncryption\Exception\WrongKeyOrModifiedCiphertextException;
use Ecotone\PHPEncryption\KeyProtectedByPassword;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @internal
 */
class PasswordTest extends TestCase
{
    public function test_key_protected_by_password_correct(): void
    {
        $pkey1 = KeyProtectedByPassword::createRandomPasswordProtectedKey('password');
        $pkey2 = KeyProtectedByPassword::loadFromAsciiSafeString($pkey1->saveToAsciiSafeString());

        $key1 = $pkey1->unlockKey('password');
        $key2 = $pkey2->unlockKey('password');

        $this->assertSame($key1->getRawBytes(), $key2->getRawBytes());
    }

    public function test_key_protected_by_password_wrong(): void
    {
        $pkey = KeyProtectedByPassword::createRandomPasswordProtectedKey('rightpassword');
        $this->expectException(WrongKeyOrModifiedCiphertextException::class);
        $pkey->unlockKey('wrongpassword');
    }

    /**
     * Check that a new password was set.
     */
    public function test_change_password(): void
    {
        $pkey1 = KeyProtectedByPassword::createRandomPasswordProtectedKey('password');
        $pkey1_enc_ascii = $pkey1->saveToAsciiSafeString();
        $key1 = $pkey1->unlockKey('password')->saveToAsciiSafeString();

        $pkey1->changePassword('password', 'new password');

        $pkey1_enc_ascii_new = $pkey1->saveToAsciiSafeString();
        $key1_new = $pkey1->unlockKey('new password')->saveToAsciiSafeString();

        // The encrypted_key should not be the same.
        $this->assertNotSame($pkey1_enc_ascii, $pkey1_enc_ascii_new);

        // The actual key should be the same.
        $this->assertSame($key1, $key1_new);
    }

    /**
     * Check that changing the password actually changes the password.
     */
    public function test_password_actually_changes(): void
    {
        $pkey1 = KeyProtectedByPassword::createRandomPasswordProtectedKey('password');
        $pkey1->changePassword('password', 'new password');

        $this->expectException(WrongKeyOrModifiedCiphertextException::class);
        $pkey1->unlockKey('password');
    }

    public function test_malformed_load(): void
    {
        $pkey1 = KeyProtectedByPassword::createRandomPasswordProtectedKey('password');
        $pkey1_enc_ascii = $pkey1->saveToAsciiSafeString();

        $pkey1_enc_ascii[0] = "\xFF";

        $this->expectException(BadFormatException::class);
        KeyProtectedByPassword::loadFromAsciiSafeString($pkey1_enc_ascii);
    }
}
