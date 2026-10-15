<?php

namespace Ecotone\Api\DataProtection;

use Ecotone\DataProtection\Encryption\Core;
use Ecotone\DataProtection\Encryption\Encoding;
use SensitiveParameter;

/**
 * licence Apache-2.0
 */
final class Key
{
    public const KEY_CURRENT_VERSION = "\xDE\xF0\x00\x00";
    public const KEY_BYTE_SIZE = 32;

    private string $key_bytes;

    private function __construct(#[SensitiveParameter] string $bytes)
    {
        Core::ensureTrue(Core::strlen($bytes) === self::KEY_BYTE_SIZE, 'Bad key length.');

        $this->key_bytes = $bytes;
    }

    public static function createNewRandomKey(): self
    {
        return new Key(Core::secureRandom(self::KEY_BYTE_SIZE));
    }

    public static function loadFromAsciiSafeString(#[SensitiveParameter] string $saved_key_string, bool $do_not_trim = false): self
    {
        if (! $do_not_trim) {
            $saved_key_string = Encoding::trimTrailingWhitespace($saved_key_string);
        }
        $key_bytes = Encoding::loadBytesFromChecksummedAsciiSafeString(self::KEY_CURRENT_VERSION, $saved_key_string);

        return new Key($key_bytes);
    }

    public function saveToAsciiSafeString(): string
    {
        return Encoding::saveBytesToChecksummedAsciiSafeString(self::KEY_CURRENT_VERSION, $this->key_bytes);
    }

    public function getRawBytes(): string
    {
        return $this->key_bytes;
    }
}
