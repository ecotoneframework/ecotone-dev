<?php

namespace Test\Ecotone\DataProtection\Unit\Encryption;

use Ecotone\DataProtection\Encryption\Core;
use Ecotone\DataProtection\Encryption\Exception\CryptoException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

use function mb_substr;

/**
 * @internal
 */
class CoreTest extends TestCase
{
    // The specific bug the following two tests check for did not fail when
    // mbstring.func_overload=0 so it is crucial to run these tests with
    // mbstring.func_overload=7 as well.

    public function test_our_substr_trailing_empty_string_bug_weird(): void
    {
        $str = hex2bin('4d8ab774261977e13049c42b4996f2c4');
        self::assertSame(16, Core::strlen($str));

        if (ini_get('mbstring.func_overload') == 7) {
            // This checks that the above hex string is indeed "weird."
            // Edit: Er... at least, on PHP 5.6.0 and above it's weird.
            //  I DON'T KNOW WHY THE LENGTH OF A STRING DEPENDS ON THE VERSION
            //  OF PHP BUT APPARENTLY IT DOES ¯\_(ツ)_/¯
            if (version_compare(phpversion(), '5.6.0', '>=')) {
                self::assertSame(12, strlen($str));
            } else {
                self::assertSame(16, strlen($str));
            }
        } else {
            self::assertSame(16, strlen($str));

            // We want ourSubstr to behave identically to substr() in PHP 7 in
            // the non-mbstring case. This double checks what that behavior is.
            if (version_compare(phpversion(), '7.0.0', '>=')) {
                self::assertSame(
                    '',
                    substr('ABC', 3, 0)
                );
                self::assertSame(
                    '',
                    substr('ABC', 3)
                );
            } else {
                // The behavior was changed for PHP 7. It used to be...
                self::assertFalse(substr('ABC', 3, 0));
                self::assertFalse(substr('ABC', 3));
            }
            // Seriously, fuck this shit. Don't use PHP. ╯‵Д′)╯彡┻━┻
        }

        // This checks that the behavior is indeed the same.
        self::assertSame('', Core::substr($str, 16));
    }

    public function test_our_substr_trailing_empty_string_bug_normal(): void
    {
        // Same as above but with a non-weird string.
        $str = 'AAAAAAAAAAAAAAAA';
        if (ini_get('mbstring.func_overload') == 7) {
            self::assertSame(16, strlen($str));
        } else {
            self::assertSame(16, strlen($str));
        }
        self::assertSame(16, Core::strlen($str));
        self::assertSame('', Core::substr($str, 16));
    }

    public function test_our_substr_out_of_borders(): void
    {
        // See: https://secure.php.net/manual/en/function.mb-substr.php#50275

        // We want to be like substr, so confirm that behavior.
        if (PHP_VERSION_ID < 80000) {
            // In PHP 8.0, substr starts returning '' instead of false.
            // Core::ourSubstr should behave the OLD way.
            self::assertFalse(substr('abc', 5, 2));
        }

        // Confirm that mb_substr does not have that behavior.
        if (function_exists('mb_substr')) {
            self::assertSame('', mb_substr('abc', 5, 2));
        }

        // Check if we actually have that behavior.
        self::assertFalse(Core::substr('abc', 5, 2));
    }

    public function test_our_substr_negative_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Core::substr('abc', 0, -1);
    }

    public function test_our_substr_negative_start(): void
    {
        self::assertSame('c', Core::substr('abc', -1, 1));
    }

    public function test_our_substr_length_is_max(): void
    {
        self::assertSame('bc', Core::substr('abc', 1, 500));
    }

    public function test_secure_random_zero_length(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('zero or negative');
        Core::secureRandom(0);
    }

    public function test_secure_random_negative_length()
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('zero or negative');
        Core::secureRandom(-1);
    }

    public function test_secure_random_positive_length()
    {
        $x = Core::secureRandom(10);
        self::assertSame(10, strlen($x));
    }
}
