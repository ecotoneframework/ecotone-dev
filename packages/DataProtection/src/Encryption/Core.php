<?php

namespace Ecotone\DataProtection\Encryption;

use function defined;

use Ecotone\DataProtection\Encryption\Exception\CryptoException;
use Ecotone\DataProtection\Encryption\Exception\EnvironmentIsBrokenException;

use function extension_loaded;
use function function_exists;

use InvalidArgumentException;

use function is_int;
use function mb_strlen;
use function mb_substr;
use function ord;
use function pack;
use function random_bytes;

use function strlen;
use function substr;

use Throwable;

/**
 * licence Apache-2.0
 */
final class Core
{
    public const HEADER_VERSION_SIZE = 4;
    public const MINIMUM_CIPHERTEXT_SIZE = 84;

    public const CURRENT_VERSION = "\xDE\xF5\x02\x00";

    public const CIPHER_METHOD = 'aes-256-ctr';
    public const BLOCK_BYTE_SIZE = 16;
    public const KEY_BYTE_SIZE = 32;
    public const SALT_BYTE_SIZE = 32;
    public const MAC_BYTE_SIZE = 32;
    public const HASH_FUNCTION_NAME = 'sha256';
    public const ENCRYPTION_INFO_STRING = 'Ecotone|KeyForEncryption';
    public const AUTHENTICATION_INFO_STRING = 'Ecotone|KeyForAuthentication';
    public const BUFFER_BYTE_SIZE = 1048576;

    /*
     * V2.0 Format: VERSION (4 bytes) || SALT (32 bytes) || IV (16 bytes) ||
     *              CIPHERTEXT (varies) || HMAC (32 bytes)
     *
     * V1.0 Format: HMAC (32 bytes) || IV (16 bytes) || CIPHERTEXT (varies).
     */

    /**
     * Adds an integer to a block-sized counter.
     *
     * @throws EnvironmentIsBrokenException
     *
     * @psalm-suppress RedundantCondition - It's valid to use is_int to check for overflow.
     */
    public static function incrementCounter(string $ctr, int $inc): string
    {
        self::ensureTrue(self::strlen($ctr) === self::BLOCK_BYTE_SIZE, 'Trying to increment a nonce of the wrong size.');
        self::ensureTrue($inc > 0, 'Trying to increment a nonce by a nonpositive amount'); // The caller is probably re-using CTR-mode keystream if they increment by 0.
        self::ensureTrue($inc <= PHP_INT_MAX - 255, 'Integer overflow may occur');

        /*
         * We start at the rightmost byte (big-endian)
         * So, too, does OpenSSL: http://stackoverflow.com/a/3146214/2224584
         */
        for ($i = self::BLOCK_BYTE_SIZE - 1; $i >= 0; --$i) {
            $sum = ord($ctr[$i]) + $inc;

            /* Detect integer overflow and fail. */
            self::ensureTrue(is_int($sum), 'Integer overflow in CTR mode nonce increment');

            $ctr[$i] = pack('C', $sum & 0xFF);
            $inc = $sum >> 8;
        }

        return $ctr;
    }

    /**
     * Returns a random byte string of the specified length.
     *
     * @throws EnvironmentIsBrokenException|CryptoException
     */
    public static function secureRandom(int $octets): string
    {
        if ($octets <= 0) {
            throw new CryptoException('A zero or negative amount of random bytes was requested.');
        }
        self::ensureFunctionExists('random_bytes');

        try {
            return random_bytes(max(1, $octets));
        } catch (Throwable $ex) {
            throw new EnvironmentIsBrokenException(message: 'Your system does not have a secure random number generator.', previous: $ex);
        }
    }

    /**
     * @throws EnvironmentIsBrokenException
     */
    public static function ensureConstantExists(string $name): void
    {
        self::ensureTrue(defined($name), 'Constant ' . $name . ' does not exists');
    }

    /**
     * @throws EnvironmentIsBrokenException
     */
    public static function ensureFunctionExists(string $name): void
    {
        self::ensureTrue(function_exists($name), 'function ' . $name . ' does not exists');
    }

    /**
     * @throws EnvironmentIsBrokenException
     */
    public static function ensureTrue(bool $condition, string $message = ''): void
    {
        if (! $condition) {
            throw new EnvironmentIsBrokenException($message);
        }
    }

    /**
     * Computes the length of a string in bytes.
     *
     * @throws EnvironmentIsBrokenException
     */
    public static function strlen(string $str): int
    {
        static $exists = null;
        if ($exists === null) {
            $exists = extension_loaded('mbstring') && function_exists('mb_strlen');
        }
        if ($exists) {
            $length = mb_strlen($str, '8bit');
            self::ensureTrue($length !== false);

            return $length;
        }

        return strlen($str);
    }

    /**
     * Behaves roughly like the function substr() in PHP 7 does.
     *
     * @throws EnvironmentIsBrokenException
     */
    public static function substr(string $str, int $start, ?int $length = null): bool|string
    {
        static $exists = null;
        if ($exists === null) {
            $exists = extension_loaded('mbstring') && function_exists('mb_substr');
        }

        // This is required to make mb_substr behavior identical to substr.
        // Without this, mb_substr() would return false, contra to what the
        // PHP documentation says (it doesn't say it can return false.)
        $input_len = self::strlen($str);
        if ($start === $input_len && ! $length) {
            return '';
        }

        if ($start > $input_len) {
            return false;
        }

        // mb_substr($str, 0, NULL, '8bit') returns an empty string on PHP 5.3,
        // so we have to find the length ourselves. Also, substr() doesn't
        // accept null for the length.
        if (! isset($length)) {
            if ($start >= 0) {
                $length = $input_len - $start;
            } else {
                $length = -$start;
            }
        }

        if ($length < 0) {
            throw new InvalidArgumentException('Negative lengths are not supported with ourSubstr.');
        }

        if ($exists) {
            $substr = mb_substr($str, $start, $length, '8bit');
            // At this point there are two cases where mb_substr can
            // legitimately return an empty string. Either $length is 0, or
            // $start is equal to the length of the string (both mb_substr and
            // substr return an empty string when this happens). It should never
            // ever return a string that's longer than $length.
            if (self::strlen($substr) > $length || (self::strlen($substr) === 0 && $length !== 0 && $start !== $input_len)) {
                throw new EnvironmentIsBrokenException('Your version of PHP has bug #66797. Its implementation of mb_substr() is incorrect. See the details here:https://bugs.php.net/bug.php?id=66797');
            }
            return $substr;
        }

        return substr($str, $start, $length);
    }
}
