<?php

namespace Test\Ecotone\DataProtection\Unit\Encryption;

use function chr;

use Ecotone\DataProtection\Encryption\Core;
use Ecotone\DataProtection\Encryption\Crypto;
use Ecotone\DataProtection\Encryption\Exception\CryptoException;
use Ecotone\DataProtection\Encryption\Exception\EnvironmentIsBrokenException;
use Ecotone\DataProtection\Encryption\Exception\WrongKeyOrModifiedCiphertextException;
use Ecotone\DataProtection\Encryption\Key;

use function ord;

use PHPUnit\Framework\TestCase;
use Random\RandomException;

use function random_bytes;
use function str_repeat;

use Throwable;

/**
 * @internal
 */
class CryptoTest extends TestCase
{
    # Test for issue #165 -- encrypting then decrypting empty string fails.
    public function test_empty_string(): void
    {
        $str    = '';
        $key    = Key::createNewRandomKey();
        $cipher = Crypto::encrypt($str, $key);
        self::assertSame($str, Crypto::decrypt($cipher, $key));
    }

    // This mirrors the one in RuntimeTests.php, but for passwords.
    // We can't runtime-test the password stuff because it runs PBKDF2.
    /**
     * @throws RandomException|CryptoException|WrongKeyOrModifiedCiphertextException|EnvironmentIsBrokenException
     */
    public function test_encrypt_decrypt_with_password(): void
    {
        $data = "EnCrYpT EvErYThInG\x00\x00";
        $password = 'password';

        // Make sure encrypting then decrypting doesn't change the message.
        $ciphertext = Crypto::encryptWithPassword($data, $password, true);
        try {
            $decrypted = Crypto::decryptWithPassword($ciphertext, $password, true);
        } catch (WrongKeyOrModifiedCiphertextException $ex) {
            // It's important to catch this and change it into a
            // Ex\EnvironmentIsBrokenException, otherwise a test failure could trick
            // the user into thinking it's just an invalid ciphertext!
            throw new EnvironmentIsBrokenException();
        }
        if ($decrypted !== $data) {
            throw new EnvironmentIsBrokenException();
        }

        // Modifying the ciphertext: Appending a string.
        try {
            Crypto::decryptWithPassword($ciphertext . 'a', $password, true);
            throw new EnvironmentIsBrokenException();
        } catch (Throwable $e) { /* expected */
            self::assertInstanceof(WrongKeyOrModifiedCiphertextException::class, $e);
        }

        // Modifying the ciphertext: Changing an HMAC byte.
        $indices_to_change = [
            0, // The header.
            Core::HEADER_VERSION_SIZE + 1, // the salt
            Core::HEADER_VERSION_SIZE + Core::SALT_BYTE_SIZE + 1, // the IV
            Core::HEADER_VERSION_SIZE + Core::SALT_BYTE_SIZE + Core::BLOCK_BYTE_SIZE + 1, // the ciphertext
        ];

        foreach ($indices_to_change as $index) {
            try {
                $ciphertext[$index] = chr((ord($ciphertext[$index]) + 1) % 256);
                Crypto::decryptWithPassword($ciphertext, $password, true);
                throw new EnvironmentIsBrokenException();
            } catch (Throwable $e) { /* expected */
                self::assertInstanceof(WrongKeyOrModifiedCiphertextException::class, $e);
            }
        }

        // Decrypting with the wrong password.
        $password       = 'password';
        $data           = 'abcdef';
        $ciphertext     = Crypto::encryptWithPassword($data, $password, true);
        $wrong_password = 'wrong_password';
        try {
            Crypto::decryptWithPassword($ciphertext, $wrong_password, true);
            throw new EnvironmentIsBrokenException();
        } catch (Throwable $e) { /* expected */
            self::assertInstanceof(WrongKeyOrModifiedCiphertextException::class, $e);
        }

        // Ciphertext too small.
        $password = random_bytes(32);
        $ciphertext = str_repeat('A', Core::MINIMUM_CIPHERTEXT_SIZE - 1);
        try {
            Crypto::decryptWithPassword($ciphertext, $password, true);
            throw new EnvironmentIsBrokenException();
        } catch (Throwable $e) { /* expected */
            self::assertInstanceof(WrongKeyOrModifiedCiphertextException::class, $e);
        }
    }

    public function test_decrypt_raw_as_hex()
    {
        $this->expectException(WrongKeyOrModifiedCiphertextException::class);

        $ciphertext = Crypto::encryptWithPassword('testdata', 'password', true);
        Crypto::decryptWithPassword($ciphertext, 'password');
    }

    public function test_decrypt_hex_as_raw(): void
    {
        $this->expectException(WrongKeyOrModifiedCiphertextException::class);

        $ciphertext = Crypto::encryptWithPassword('testdata', 'password');
        Crypto::decryptWithPassword($ciphertext, 'password', true);
    }
}
