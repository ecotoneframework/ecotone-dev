<?php

namespace Ecotone\PHPEncryption;

use Ecotone\PHPEncryption\Exception\CryptoException;
use Ecotone\PHPEncryption\Exception\EnvironmentIsBrokenException;
use Ecotone\PHPEncryption\Exception\WrongKeyOrModifiedCiphertextException;
use SensitiveParameter;
use Throwable;

use function hash_hmac;
use function is_string;
use function openssl_decrypt;
use function openssl_encrypt;

/**
 * licence Apache-2.0
 */
class Crypto
{
    /**
     * Encrypts a string with a Key.
     *
     * @throws WrongKeyOrModifiedCiphertextException|EnvironmentIsBrokenException|CryptoException
     */
    public static function encrypt(string $plaintext, Key $key, bool $raw_binary = false): string
    {
        return self::encryptInternal($plaintext, KeyOrPassword::createFromKey($key), $raw_binary);
    }

    /**
     * Encrypts a string with a password, using a slow key derivation function to make password cracking more expensive.
     *
     * @throws WrongKeyOrModifiedCiphertextException|EnvironmentIsBrokenException|CryptoException
     */
    public static function encryptWithPassword(string $plaintext, #[SensitiveParameter] string $password, bool $raw_binary = false): string
    {
        return self::encryptInternal($plaintext, KeyOrPassword::createFromPassword($password), $raw_binary);
    }

    /**
     * Decrypts a ciphertext to a string with a Key.
     *
     * @throws WrongKeyOrModifiedCiphertextException|EnvironmentIsBrokenException|CryptoException
     */
    public static function decrypt(string $ciphertext, Key $key, bool $raw_binary = false): string
    {
        return self::decryptInternal($ciphertext, KeyOrPassword::createFromKey($key), $raw_binary);
    }

    /**
     * Decrypts a ciphertext to a string with a password, using a slow key * derivation function to make password cracking more expensive.
     *
     * @throws WrongKeyOrModifiedCiphertextException|EnvironmentIsBrokenException|CryptoException
     */
    public static function decryptWithPassword(string $ciphertext, #[SensitiveParameter] string $password, bool $raw_binary = false): string
    {
        return self::decryptInternal($ciphertext, KeyOrPassword::createFromPassword($password), $raw_binary);
    }

    /**
     * Decrypts a legacy ciphertext produced by version 1 of this library.
     *
     * @throws WrongKeyOrModifiedCiphertextException|EnvironmentIsBrokenException
     */
    public static function legacyDecrypt(string $ciphertext, #[SensitiveParameter] string $key): string
    {
        RuntimeTests::runtimeTest();

        // Extract the HMAC from the front of the ciphertext.
        if (Core::strlen($ciphertext) <= Core::LEGACY_MAC_BYTE_SIZE) {
            throw new WrongKeyOrModifiedCiphertextException('Ciphertext is too short.');
        }

        $hmac = Core::substr($ciphertext, 0, Core::LEGACY_MAC_BYTE_SIZE);
        Core::ensureTrue(is_string($hmac));

        $messageCiphertext = Core::substr($ciphertext, Core::LEGACY_MAC_BYTE_SIZE);
        Core::ensureTrue(is_string($messageCiphertext));

        // Regenerate the same authentication sub-key.
        $akey = Core::HKDF(
            hash: Core::LEGACY_HASH_FUNCTION_NAME,
            ikm: $key,
            length: Core::LEGACY_KEY_BYTE_SIZE,
            info: Core::LEGACY_AUTHENTICATION_INFO_STRING,
        );

        if (self::verifyHMAC($hmac, $messageCiphertext, $akey)) {
            // Regenerate the same encryption sub-key.
            $ekey = Core::HKDF(
                hash: Core::LEGACY_HASH_FUNCTION_NAME,
                ikm: $key,
                length: Core::LEGACY_KEY_BYTE_SIZE,
                info: Core::LEGACY_ENCRYPTION_INFO_STRING,
            );

            // Extract the IV from the ciphertext.
            if (Core::strlen($messageCiphertext) <= Core::LEGACY_BLOCK_BYTE_SIZE) {
                throw new WrongKeyOrModifiedCiphertextException('Ciphertext is too short.');
            }

            $iv = Core::substr($messageCiphertext, 0, Core::LEGACY_BLOCK_BYTE_SIZE);
            Core::ensureTrue(is_string($iv));

            $actualCiphertext = Core::substr($messageCiphertext, Core::LEGACY_BLOCK_BYTE_SIZE);
            Core::ensureTrue(is_string($actualCiphertext));

            // Do the decryption.
            return self::plainDecrypt($actualCiphertext, $ekey, $iv, Core::LEGACY_CIPHER_METHOD);
        }

        throw new WrongKeyOrModifiedCiphertextException('Integrity check failed.');
    }

    /**
     * Encrypts a string with either a key or a password.
     *
     * @throws CryptoException
     */
    private static function encryptInternal(string $plaintext, KeyOrPassword $secret, bool $raw_binary): string
    {
        RuntimeTests::runtimeTest();

        $salt = Core::secureRandom(Core::SALT_BYTE_SIZE);
        $keys = $secret->deriveKeys($salt);
        $iv = Core::secureRandom(Core::BLOCK_BYTE_SIZE);

        $ciphertext = Core::CURRENT_VERSION . $salt . $iv . self::plainEncrypt($plaintext, $keys->encryptionKey, $iv);
        $auth = hash_hmac(Core::HASH_FUNCTION_NAME, $ciphertext, $keys->authenticationKey, true);
        $ciphertext .= $auth;

        if ($raw_binary) {
            return $ciphertext;
        }

        return Encoding::binToHex($ciphertext);
    }

    /**
     * Decrypts a ciphertext to a string with either a key or a password.
     *
     * @throws WrongKeyOrModifiedCiphertextException|EnvironmentIsBrokenException|CryptoException
     */
    private static function decryptInternal(string $ciphertext, KeyOrPassword $secret, bool $raw_binary): string
    {
        RuntimeTests::runtimeTest();

        if (! $raw_binary) {
            try {
                $ciphertext = Encoding::hexToBin($ciphertext);
            } catch (Throwable $ex) {
                throw new WrongKeyOrModifiedCiphertextException(message: 'Ciphertext has invalid hex encoding.', previous: $ex);
            }
        }

        if (Core::strlen($ciphertext) < Core::MINIMUM_CIPHERTEXT_SIZE) {
            throw new WrongKeyOrModifiedCiphertextException('Ciphertext is too short.');
        }

        // Get and check the version header.
        $header = Core::substr($ciphertext, 0, Core::HEADER_VERSION_SIZE);
        if ($header !== Core::CURRENT_VERSION) {
            throw new WrongKeyOrModifiedCiphertextException('Bad version header.');
        }

        // Get the salt.
        $salt = Core::substr($ciphertext, Core::HEADER_VERSION_SIZE, Core::SALT_BYTE_SIZE);
        Core::ensureTrue(is_string($salt));

        // Get the IV.
        $iv = Core::substr($ciphertext, Core::HEADER_VERSION_SIZE + Core::SALT_BYTE_SIZE, Core::BLOCK_BYTE_SIZE);
        Core::ensureTrue(is_string($iv));

        // Get the HMAC.
        /** @var string $hmac */
        $hmac = Core::substr($ciphertext, Core::strlen($ciphertext) - Core::MAC_BYTE_SIZE, Core::MAC_BYTE_SIZE);
        Core::ensureTrue(is_string($hmac));

        // Get the actual encrypted ciphertext.
        /** @var string $encrypted */
        $encrypted = Core::substr(
            str: $ciphertext,
            start: Core::HEADER_VERSION_SIZE + Core::SALT_BYTE_SIZE + Core::BLOCK_BYTE_SIZE,
            length: Core::strlen($ciphertext) - Core::MAC_BYTE_SIZE - Core::SALT_BYTE_SIZE - Core::BLOCK_BYTE_SIZE - Core::HEADER_VERSION_SIZE,
        );
        Core::ensureTrue(is_string($encrypted));

        // Derive the separate encryption and authentication keys from the key
        // or password, whichever it is.
        $keys = $secret->deriveKeys($salt);

        if (self::verifyHMAC($hmac, $header . $salt . $iv . $encrypted, $keys->authenticationKey)) {
            return self::plainDecrypt($encrypted, $keys->encryptionKey, $iv, Core::CIPHER_METHOD);
        }

        throw new WrongKeyOrModifiedCiphertextException('Integrity check failed.');
    }

    /**
     * Raw unauthenticated encryption (insecure on its own).
     *
     * @throws EnvironmentIsBrokenException
     */
    protected static function plainEncrypt(string $plaintext, #[SensitiveParameter] string $key, #[SensitiveParameter] string $iv): string
    {
        Core::ensureConstantExists('OPENSSL_RAW_DATA');
        Core::ensureFunctionExists('openssl_encrypt');

        $ciphertext = openssl_encrypt($plaintext, Core::CIPHER_METHOD, $key, OPENSSL_RAW_DATA, $iv);
        Core::ensureTrue(is_string($ciphertext), 'openssl_encrypt() failed');

        return $ciphertext;
    }

    /**
     * Raw unauthenticated decryption (insecure on its own).
     *
     * @throws EnvironmentIsBrokenException
     */
    protected static function plainDecrypt(string $ciphertext, #[SensitiveParameter] string $key, #[SensitiveParameter] string $iv, string $cipherMethod): string
    {
        Core::ensureConstantExists('OPENSSL_RAW_DATA');
        Core::ensureFunctionExists('openssl_decrypt');

        $plaintext = openssl_decrypt($ciphertext, $cipherMethod, $key, OPENSSL_RAW_DATA, $iv);
        Core::ensureTrue(is_string($plaintext), 'openssl_decrypt() failed.');

        return $plaintext;
    }

    /**
     * Verifies an HMAC without leaking information through side-channels.
     *
     * @throws EnvironmentIsBrokenException
     */
    protected static function verifyHMAC(string $expected_hmac, string $message, #[SensitiveParameter] string $key): bool
    {
        $message_hmac = hash_hmac(Core::HASH_FUNCTION_NAME, $message, $key, true);

        return Core::hashEquals($message_hmac, $expected_hmac);
    }
}
