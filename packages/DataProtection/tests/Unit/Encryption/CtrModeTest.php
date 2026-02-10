<?php

namespace Test\Ecotone\DataProtection\Unit\Encryption;

use Ecotone\DataProtection\Encryption\Core;
use Ecotone\DataProtection\Encryption\Exception\EnvironmentIsBrokenException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class CtrModeTest extends TestCase
{
    public static function counterTestVectorProvider(): array
    {
        return [
            /* First byte, no overflow. */
            [
                '00000000000000000000000000000000',
                '00000000000000000000000000000001',
                1,
            ],
            [
                '00000000000000000000000000000000',
                '000000000000000000000000000000ff',
                0xFF,
            ],
            /* First byte, with overflow. */
            [
                '00000000000000000000000000000000',
                '00000000000000000000000000000101',
                0x101,
            ],
            [
                '000000000000000000000000000000ff',
                '00000000000000000000000000000101',
                0x2,
            ],
            /* Long carry across multiple bytes. */
            [
                '101100000000000000ffffffffffff00',
                '10110000000000000100000000000000',
                0x100,
            ],
            [
                '0fffffffffffffffffffffffffffff00',
                '10000000000000000000000000000001',
                0x101,
            ],
            /* Overflow the whole thing. */
            [
                'ffffffffffffffffffffffffffffffff',
                '00000000000000000000000000000000',
                0x1,
            ],
            [
                'ffffffffffffffffffffffffffffffff',
                '00000000000000000000000000000001',
                0x2,
            ],
            [
                'ffffffffffffffffffffffffffffffff',
                '0000000000000000000000000000beef',
                0xbeef + 1,
            ],
        ];
    }

    #[DataProvider('counterTestVectorProvider')]
    public function test_increment_counter_test_vector($start, $end, $inc): void
    {
        $actual_end = Core::incrementCounter(\hex2bin($start), $inc);
        self::assertSame($end, \bin2hex($actual_end), $start . ' + ' . $inc);
    }

    public function test_fuzz_increment_counter(): void
    {
        /* Test carry propagation. */
        for ($offset = 0; $offset < 16; $offset++) {
            /*
             * If we start with...
             *      FF FF FF FF FE FF FF ... FF
             *                   ^- offset
             *
             * And add 1, we should get...
             *
             *      FF FF FF FF FF 00 00 ... 00
                                 ^- offset
             */
            $start        = str_repeat("\xFF", $offset) . "\xFE" . str_repeat("\xFF", 16 - $offset - 1);
            $expected_end = str_repeat("\xFF", $offset + 1) . str_repeat("\x00", 16 - $offset - 1);
            $actual_end   = Core::incrementCounter($start, 1);
            self::assertSame(
                \bin2hex($expected_end),
                \bin2hex($actual_end),
                \bin2hex($start) . ' + ' . 1
            );
        }

        /* Try using it to add random 24-bit integers, and check the result. */
        for ($trial = 0; $trial < 1000; $trial++) {
            $rand_a = mt_rand() & 0x00ffffff;
            $rand_b = mt_rand() & 0x00ffffff;

            $prefix = openssl_random_pseudo_bytes(12);

            $start = $prefix .
                chr(($rand_a >> 24) & 0xff) .
                chr(($rand_a >> 16) & 0xff) .
                chr(($rand_a >> 8) & 0xff) .
                chr(($rand_a >> 0) & 0xff);

            $sum = $rand_a + $rand_b;

            $expected_end = $prefix .
                chr(($sum >> 24) & 0xff) .
                chr(($sum >> 16) & 0xff) .
                chr(($sum >> 8) & 0xff) .
                chr(($sum >> 0) & 0xff);
            $actual_end = Core::incrementCounter($start, $rand_b);

            self::assertSame(
                \bin2hex($expected_end),
                \bin2hex($actual_end),
                \bin2hex($start) . ' + ' . $rand_b
            );
        }
    }

    public function test_increment_by_negative_value(): void
    {
        $this->expectException(EnvironmentIsBrokenException::class);

        Core::incrementCounter(str_repeat("\x00", 16), -1);
    }

    public function test_increment_by_zero(): void
    {
        $this->expectException(EnvironmentIsBrokenException::class);

        Core::incrementCounter(str_repeat("\x00", 16), 0);
    }

    public static function allNonZeroByteValuesProvider(): array
    {
        $all_bytes = [];
        for ($i = 1; $i <= 0xff; $i++) {
            $all_bytes[] = [$i];
        }
        return $all_bytes;
    }

    #[DataProvider('allNonZeroByteValuesProvider')]
    public function test_increment_causing_overflow_in_first_byte($lsb): void
    {
        $this->expectException(EnvironmentIsBrokenException::class);

        /* Smallest value that will overflow. */
        $increment = (PHP_INT_MAX - $lsb) + 1;
        $start     = str_repeat("\x00", 15) . chr($lsb);

        Core::incrementCounter($start, $increment);
    }

    public function test_increment_with_short_iv_length(): void
    {
        $this->expectException(EnvironmentIsBrokenException::class);

        Core::incrementCounter(str_repeat("\x00", 15), 1);
    }

    public function test_increment_with_long_iv_length(): void
    {
        $this->expectException(EnvironmentIsBrokenException::class);

        Core::incrementCounter(str_repeat("\x00", 17), 1);
    }

    public function test_compatibility_with_open_ssl(): void
    {
        /* Plaintext is 0x300 blocks. */
        $plaintext = str_repeat('a', 0x300 * 16);

        /* Start at zero. */
        $starting_nonce = str_repeat("\x00", 16);

        $ciphertext = openssl_encrypt(
            $plaintext,
            Core::CIPHER_METHOD,
            'YELLOW SUBMARINE',
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $starting_nonce
        );

        /* Take the second half, the last 0x150 blocks. */
        $cipher_lasthalf = mb_substr($ciphertext, 0x150 * 16, 0x150 * 16, '8bit');

        /* Compute what the nonce should be at the start of the last half. */
        $computed_nonce = Core::incrementCounter(
            $starting_nonce,
            0x150
        );

        /* Try to decrypt it using that nonce. */
        $decrypt = openssl_decrypt(
            $cipher_lasthalf,
            Core::CIPHER_METHOD,
            'YELLOW SUBMARINE',
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $computed_nonce
        );

        /* If it decrypts properly, we computed the nonce the same way. */
        self::assertSame(
            str_repeat('a', 0x150 * 16),
            $decrypt
        );
    }
}
