<?php

namespace Ecotone\PHPEncryption;

use Ecotone\PHPEncryption\Exception\CryptoException;
use Ecotone\PHPEncryption\Exception\EnvironmentIsBrokenException;
use InvalidArgumentException;
use SensitiveParameter;
use Throwable;
use function ceil;
use function chr;
use function defined;
use function extension_loaded;
use function function_exists;
use function hash;
use function hash_algos;
use function hash_equals;
use function hash_hkdf;
use function hash_hmac;
use function hash_pbkdf2;
use function in_array;
use function is_callable;
use function is_int;
use function is_null;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function ord;
use function pack;
use function random_bytes;
use function str_repeat;
use function strlen;
use function strtolower;
use function substr;

/**
 * licence Apache-2.0
 */
final class Core
{
    public const int HEADER_VERSION_SIZE = 4;
    public const int MINIMUM_CIPHERTEXT_SIZE = 84;

    public const string CURRENT_VERSION = "\xDE\xF5\x02\x00";

    public const string CIPHER_METHOD = 'aes-256-ctr';
    public const int BLOCK_BYTE_SIZE = 16;
    public const int KEY_BYTE_SIZE = 32;
    public const int SALT_BYTE_SIZE = 32;
    public const int MAC_BYTE_SIZE = 32;
    public const string HASH_FUNCTION_NAME = 'sha256';
    public const string ENCRYPTION_INFO_STRING = 'Ecotone|V2|KeyForEncryption';
    public const string AUTHENTICATION_INFO_STRING = 'Ecotone|V2|KeyForAuthentication';
    public const int BUFFER_BYTE_SIZE = 1048576;

    public const string LEGACY_CIPHER_METHOD = 'aes-128-cbc';
    public const int LEGACY_BLOCK_BYTE_SIZE = 16;
    public const int LEGACY_KEY_BYTE_SIZE = 16;
    public const string LEGACY_HASH_FUNCTION_NAME = 'sha256';
    public const int LEGACY_MAC_BYTE_SIZE = 32;
    public const string LEGACY_ENCRYPTION_INFO_STRING = 'Ecotone|KeyForEncryption';
    public const string LEGACY_AUTHENTICATION_INFO_STRING = 'Ecotone|KeyForAuthentication';

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
        self::ensureTrue(is_int($inc), 'Trying to increment nonce by a non-integer.');
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
     * Computes the HKDF key derivation function specified in http://tools.ietf.org/html/rfc5869.
     *
     * @throws EnvironmentIsBrokenException
     *
     * @psalm-suppress UndefinedFunction - We're checking if the function exists first.
     */
    public static function HKDF(string $hash, string $ikm, int $length, string $info = '', ?string $salt = null): string
    {
        static $nativeHKDF = null;
        if ($nativeHKDF === null) {
            $nativeHKDF = is_callable('\\hash_hkdf');
        }
        if ($nativeHKDF) {
            if (is_null($salt)) {
                $salt = '';
            }
            return hash_hkdf($hash, $ikm, $length, $info, $salt);
        }

        $digest_length = self::strlen(hash_hmac($hash, '', '', true));

        // Sanity-check the desired output length.
        self::ensureTrue(! empty($length) && is_int($length) && $length >= 0 && $length <= 255 * $digest_length, 'Bad output length requested of HDKF.');

        // "if [salt] not provided, is set to a string of HashLen zeroes."
        if (is_null($salt)) {
            $salt = str_repeat("\x00", $digest_length);
        }

        // HKDF-Extract:
        // PRK = HMAC-Hash(salt, IKM)
        // The salt is the HMAC key.
        $prk = hash_hmac($hash, $ikm, $salt, true);

        // HKDF-Expand:

        // This check is useless, but it serves as a reminder to the spec.
        self::ensureTrue(self::strlen($prk) >= $digest_length);

        // T(0) = ''
        $t = '';
        $last_block = '';
        for ($block_index = 1; self::strlen($t) < $length; ++$block_index) {
            // T(i) = HMAC-Hash(PRK, T(i-1) | info | 0x??)
            $last_block = hash_hmac(
                $hash,
                $last_block . $info . chr($block_index),
                $prk,
                true
            );
            // T = T(1) | T(2) | T(3) | ... | T(N)
            $t .= $last_block;
        }

        // ORM = first L octets of T
        /** @var string $orm */
        $orm = self::substr($t, 0, $length);
        self::ensureTrue(is_string($orm));

        return $orm;
    }

    /**
     * @throws EnvironmentIsBrokenException|CryptoException
     */
    public static function hashEquals(string $expected, string $given): bool
    {
        static $native = null;
        if ($native === null) {
            $native = function_exists('hash_equals');
        }
        if ($native) {
            return hash_equals($expected, $given);
        }

        // We can't just compare the strings with '==', since it would make
        // timing attacks possible. We could use the XOR-OR constant-time
        // comparison algorithm, but that may not be a reliable defense in an
        // interpreted language. So we use the approach of HMACing both strings
        // with a random key and comparing the HMACs.

        // We're not attempting to make variable-length string comparison
        // secure, as that's very difficult. Make sure the strings are the same
        // length.
        self::ensureTrue(self::strlen($expected) === self::strlen($given));

        $blind = self::secureRandom(32);
        $message_compare = hash_hmac(self::HASH_FUNCTION_NAME, $given, $blind);
        $correct_compare = hash_hmac(self::HASH_FUNCTION_NAME, $expected, $blind);

        return $correct_compare === $message_compare;
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

    /*
     * We need these strlen() and substr() functions because when
     * 'mbstring.func_overload' is set in php.ini, the standard strlen() and
     * substr() are replaced by mb_strlen() and mb_substr().
     */

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

    /**
     * Computes the PBKDF2 password-based key derivation function.
     *
     * The PBKDF2 function is defined in RFC 2898. Test vectors can be found in
     * RFC 6070. This implementation of PBKDF2 was originally created by Taylor
     * Hornby, with improvements from http://www.variations-of-shadow.com/.
     *
     * @throws EnvironmentIsBrokenException
     *
     */
    public static function pbkdf2(
        string $algorithm,
        #[SensitiveParameter] string $password,
        string $salt,
        int $count,
        int $key_length,
        bool $raw_output = false
    ): string {
        // Coerce strings to integers with no information loss or overflow
        $count += 0;
        $key_length += 0;

        $algorithm = strtolower($algorithm);
        self::ensureTrue(in_array($algorithm, hash_algos(), true), 'Invalid or unsupported hash algorithm.');

        // Whitelist, or we could end up with people using CRC32.
        $ok_algorithms = ['sha1', 'sha224', 'sha256', 'sha384', 'sha512', 'ripemd160', 'ripemd256', 'ripemd320', 'whirlpool'];

        self::ensureTrue(in_array($algorithm, $ok_algorithms, true), 'Algorithm is not a secure cryptographic hash function.');
        self::ensureTrue($count > 0 && $key_length > 0, 'Invalid PBKDF2 parameters.');

        if (function_exists('hash_pbkdf2')) {
            // The output length is in NIBBLES (4-bits) if $raw_output is false!
            if (! $raw_output) {
                $key_length *= 2;
            }
            return hash_pbkdf2($algorithm, $password, $salt, $count, $key_length, $raw_output);
        }

        $hash_length = self::strlen(hash($algorithm, '', true));
        $block_count = ceil($key_length / $hash_length);

        $output = '';
        for ($i = 1; $i <= $block_count; $i++) {
            // $i encoded as 4 bytes, big endian.
            $last = $salt . pack('N', $i);
            // first iteration
            $last = $xorsum = hash_hmac($algorithm, $last, $password, true);
            // perform the other $count - 1 iterations
            for ($j = 1; $j < $count; $j++) {
                /**
                 * @psalm-suppress InvalidOperand
                 */
                $xorsum ^= ($last = hash_hmac($algorithm, $last, $password, true));
            }
            $output .= $xorsum;
        }

        if ($raw_output) {
            return (string)self::substr($output, 0, $key_length);
        }

        return Encoding::binToHex((string)self::substr($output, 0, $key_length));
    }
}
