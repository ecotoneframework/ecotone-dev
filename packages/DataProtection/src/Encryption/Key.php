<?php

namespace Ecotone\DataProtection\Encryption;

use Ecotone\DataProtection\Encryption\Exception\BadFormatException;
use Ecotone\DataProtection\Encryption\Exception\CryptoException;
use Ecotone\DataProtection\Encryption\Exception\EnvironmentIsBrokenException;
use SensitiveParameter;

/**
 * licence Apache-2.0
 */
final class Key
{
    public const KEY_CURRENT_VERSION = "\xDE\xF0\x00\x00";
    public const KEY_BYTE_SIZE = 32;

    private string $key_bytes;

    /**
     * Constructs a new Key object from a string of raw bytes.
     *
     * @throws EnvironmentIsBrokenException
     */
    private function __construct(#[SensitiveParameter] string $bytes)
    {
        Core::ensureTrue(Core::strlen($bytes) === self::KEY_BYTE_SIZE, 'Bad key length.');

        $this->key_bytes = $bytes;
    }

    /**
     * Creates a new random key.
     *
     * @throws CryptoException|EnvironmentIsBrokenException
     */
    public static function createNewRandomKey(): self
    {
        return new Key(Core::secureRandom(self::KEY_BYTE_SIZE));
    }

    /**
     * Loads a Key from its encoded form.
     *
     * By default, this function will call Encoding::trimTrailingWhitespace() to remove trailing CR, LF, NUL, TAB, and SPACE characters, which are commonly appended to files when working with text editors.
     *
     * @throws CryptoException|EnvironmentIsBrokenException|BadFormatException
     */
    public static function loadFromAsciiSafeString(#[SensitiveParameter] string $saved_key_string, bool $do_not_trim = false): self
    {
        if (! $do_not_trim) {
            $saved_key_string = Encoding::trimTrailingWhitespace($saved_key_string);
        }
        $key_bytes = Encoding::loadBytesFromChecksummedAsciiSafeString(self::KEY_CURRENT_VERSION, $saved_key_string);

        return new Key($key_bytes);
    }

    /**
     * Encodes the Key into a string of printable ASCII characters.
     *
     * @throws EnvironmentIsBrokenException
     */
    public function saveToAsciiSafeString(): string
    {
        return Encoding::saveBytesToChecksummedAsciiSafeString(self::KEY_CURRENT_VERSION, $this->key_bytes);
    }

    /**
     * Gets the raw bytes of the key.
     */
    public function getRawBytes(): string
    {
        return $this->key_bytes;
    }
}
