<?php

namespace Ecotone\DataProtection\Encryption;

use Ecotone\DataProtection\Encryption\Exception\BadFormatException;
use Ecotone\DataProtection\Encryption\Exception\CryptoException;
use Ecotone\DataProtection\Encryption\Exception\EnvironmentIsBrokenException;

use Ecotone\DataProtection\Encryption\Exception\WrongKeyOrModifiedCiphertextException;
use function hash;

use SensitiveParameter;

/**
 * licence Apache-2.0
 */
final class KeyProtectedByPassword
{
    public const PASSWORD_KEY_CURRENT_VERSION = "\xDE\xF1\x00\x00";

    private function __construct(private string $encrypted_key)
    {
    }

    /**
     * Creates a random key protected by the provided password.
     *
     * @throws EnvironmentIsBrokenException|CryptoException
     */
    public static function createRandomPasswordProtectedKey(#[SensitiveParameter] string $password): self
    {
        $inner_key = Key::createNewRandomKey();
        /* The password is hashed as a form of poor-man's domain separation
         * between this use of encryptWithPassword() and other uses of
         * encryptWithPassword() that the user may also be using as part of the
         * same protocol. */
        $encrypted_key = Crypto::encryptWithPassword($inner_key->saveToAsciiSafeString(), hash(Core::HASH_FUNCTION_NAME, $password, true), true);

        return new KeyProtectedByPassword($encrypted_key);
    }

    /**
     * Loads a KeyProtectedByPassword from its encoded form.
     *
     * @throws BadFormatException|EnvironmentIsBrokenException|CryptoException
     */
    public static function loadFromAsciiSafeString(#[SensitiveParameter]string $saved_key_string): self
    {
        $encrypted_key = Encoding::loadBytesFromChecksummedAsciiSafeString(
            self::PASSWORD_KEY_CURRENT_VERSION,
            $saved_key_string
        );

        return new KeyProtectedByPassword($encrypted_key);
    }

    /**
     * Encodes the KeyProtectedByPassword into a string of printable ASCII characters.
     *
     * @throws EnvironmentIsBrokenException|CryptoException
     */
    public function saveToAsciiSafeString(): string
    {
        return Encoding::saveBytesToChecksummedAsciiSafeString(self::PASSWORD_KEY_CURRENT_VERSION, $this->encrypted_key);
    }

    /**
     * Decrypts the protected key, returning an unprotected Key object that can be used for encryption and decryption.
     *
     * @throws CryptoException|EnvironmentIsBrokenException|WrongKeyOrModifiedCiphertextException
     */
    public function unlockKey(#[SensitiveParameter] string $password): Key
    {
        try {
            $inner_key_encoded = Crypto::decryptWithPassword($this->encrypted_key, hash(Core::HASH_FUNCTION_NAME, $password, true), true);

            return Key::loadFromAsciiSafeString($inner_key_encoded);
        } catch (BadFormatException $ex) {
            /* This should never happen unless an attacker replaced the
             * encrypted key ciphertext with some other ciphertext that was
             * encrypted with the same password. We transform the exception type
             * here in order to make the API simpler, avoiding the need to
             * document that this method might throw an Ex\BadFormatException. */
            throw new WrongKeyOrModifiedCiphertextException('The decrypted key was found to be in an invalid format. This very likely indicates it was modified by an attacker.', previous: $ex);
        }
    }

    /**
     * Changes the password.
     *
     * @throws CryptoException|EnvironmentIsBrokenException|WrongKeyOrModifiedCiphertextException
     */
    public function changePassword(#[SensitiveParameter]string $current_password, #[SensitiveParameter] string $new_password): static
    {
        $inner_key = $this->unlockKey($current_password);
        /* The password is hashed as a form of poor-man's domain separation
         * between this use of encryptWithPassword() and other uses of
         * encryptWithPassword() that the user may also be using as part of the
         * same protocol. */
        $encrypted_key = Crypto::encryptWithPassword($inner_key->saveToAsciiSafeString(), hash(Core::HASH_FUNCTION_NAME, $new_password, true), true);

        $this->encrypted_key = $encrypted_key;

        return $this;
    }
}
