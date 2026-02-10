<?php

namespace Test\Ecotone\DataProtection\Unit\Encryption;

use Ecotone\DataProtection\Encryption\Core;
use Ecotone\DataProtection\Encryption\Crypto;
use Ecotone\DataProtection\Encryption\Exception\IOException;
use Ecotone\DataProtection\Encryption\Exception\WrongKeyOrModifiedCiphertextException;
use Ecotone\DataProtection\Encryption\File;
use Ecotone\DataProtection\Encryption\Key;
use Exception;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class FileTest extends TestCase
{
    private $key;
    private static $FILE_DIR;
    private static $TEMP_DIR;

    public function setUp(): void
    {
        self::$FILE_DIR = __DIR__ . '/../../Fixture/files';
        self::$TEMP_DIR = self::$FILE_DIR . '/tmp';
        if (! is_dir(self::$TEMP_DIR)) {
            mkdir(self::$TEMP_DIR);
        }

        $this->key = Key::createNewRandomKey();
    }

    public function tearDown(): void
    {
        array_map('unlink', glob(self::$TEMP_DIR . '/*'));
        rmdir(self::$TEMP_DIR);
    }

    /**
     * Test encryption from one file name to a destination file name
     */
    #[DataProvider('fileToFileProvider')]
    public function test_file_to_file(string $srcName): void
    {
        $src = self::$FILE_DIR . '/' . $srcName;

        $dest1  = self::$TEMP_DIR . '/ff1';
        File::encryptFile($src, $dest1, $this->key);
        self::assertFileExists($dest1, 'destination file not created.');

        $reverse1 = self::$TEMP_DIR . '/rv1';
        File::decryptFile($dest1, $reverse1, $this->key);
        self::assertFileExists($reverse1);
        self::assertSame(
            md5_file($src),
            md5_file($reverse1),
            'File and encrypted-decrypted file do not match.'
        );

        $dest2  = self::$TEMP_DIR . '/ff2';
        File::encryptFile($reverse1, $dest2, $this->key);
        self::assertFileExists($dest2);

        self::assertNotEquals(
            md5_file($dest1),
            md5_file($dest2),
            'First and second encryption produced identical files.'
        );

        $reverse2 = self::$TEMP_DIR . '/rv2';
        File::decryptFile($dest2, $reverse2, $this->key);
        self::assertSame(
            md5_file($src),
            md5_file($reverse2),
            'File and encrypted-decrypted file do not match.'
        );
    }

    /**
     * Test encryption from one file name to a destination file name (password).
     */
    #[DataProvider('fileToFileProvider')]
    public function test_file_to_file_with_password(string $srcName): void
    {
        $src = self::$FILE_DIR . '/' . $srcName;

        $dest1  = self::$TEMP_DIR . '/ff1';
        File::encryptFileWithPassword($src, $dest1, 'password');
        self::assertFileExists($dest1, 'destination file not created.');

        $reverse1 = self::$TEMP_DIR . '/rv1';
        File::decryptFileWithPassword($dest1, $reverse1, 'password');
        self::assertFileExists($reverse1);
        self::assertSame(
            md5_file($src),
            md5_file($reverse1),
            'File and encrypted-decrypted file do not match.'
        );

        $dest2  = self::$TEMP_DIR . '/ff2';
        File::encryptFileWithPassword($reverse1, $dest2, 'password');
        self::assertFileExists($dest2);

        self::assertNotEquals(
            md5_file($dest1),
            md5_file($dest2),
            'First and second encryption produced identical files.'
        );

        $reverse2 = self::$TEMP_DIR . '/rv2';
        File::decryptFileWithPassword($dest2, $reverse2, 'password');
        self::assertSame(
            md5_file($src),
            md5_file($reverse2),
            'File and encrypted-decrypted file do not match.'
        );
    }

    #[DataProvider('fileToFileProvider')]
    public function test_resource_to_resource(string $srcFile): void
    {
        $srcName  = self::$FILE_DIR . '/' . $srcFile;
        $destName = self::$TEMP_DIR . "/$srcFile.dest";
        $src      = fopen($srcName, 'r');
        $dest     = fopen($destName, 'w');

        File::encryptResource($src, $dest, $this->key);

        fclose($src);
        fclose($dest);

        $src2  = fopen($destName, 'r');
        $dest2 = fopen(self::$TEMP_DIR . '/dest2', 'w');

        File::decryptResource($src2, $dest2, $this->key);
        fclose($src2);
        fclose($dest2);

        self::assertSame(
            md5_file($srcName),
            md5_file(self::$TEMP_DIR . '/dest2'),
            'Original file mismatches the result of encrypt and decrypt'
        );
    }

    #[DataProvider('fileToFileProvider')]
    public function test_resource_to_resource_with_password(string $srcFile): void
    {
        $srcName  = self::$FILE_DIR . '/' . $srcFile;
        $destName = self::$TEMP_DIR . "/$srcFile.dest";
        $src      = fopen($srcName, 'r');
        $dest     = fopen($destName, 'w');

        File::encryptResourceWithPassword($src, $dest, 'password');

        fclose($src);
        fclose($dest);

        $src2  = fopen($destName, 'r');
        $dest2 = fopen(self::$TEMP_DIR . '/dest2', 'w');

        File::decryptResourceWithPassword($src2, $dest2, 'password');
        fclose($src2);
        fclose($dest2);

        self::assertSame(
            md5_file($srcName),
            md5_file(self::$TEMP_DIR . '/dest2'),
            'Original file mismatches the result of encrypt and decrypt'
        );
    }

    public function test_decrypt_bad_magic_number(): void
    {
        $junk = self::$TEMP_DIR . '/junk';
        file_put_contents($junk, 'This file does not have the right magic number.');
        $this->expectException(WrongKeyOrModifiedCiphertextException::class);
        $this->expectExceptionMessage('Input file is too small to have been created by this library.');
        File::decryptFile($junk, self::$TEMP_DIR . '/unjunked', $this->key);
    }

    #[DataProvider('garbageCiphertextProvider')]
    public function test_decrypt_garbage(string $ciphertext): void
    {
        $junk = self::$TEMP_DIR . '/junk';
        file_put_contents($junk, $ciphertext);
        $this->expectException(WrongKeyOrModifiedCiphertextException::class);
        File::decryptFile($junk, self::$TEMP_DIR . '/unjunked', $this->key);
    }

    public static function garbageCiphertextProvider()
    {
        $ciphertexts = [
            [str_repeat('this is not anything that can be decrypted.', 100)],
        ];
        for ($i = 0; $i < 1024; $i++) {
            $ciphertexts[] = [Core::CURRENT_VERSION . str_repeat('A', $i)];
        }
        return $ciphertexts;
    }

    public function test_decrypt_empty_file(): void
    {
        $junk = self::$TEMP_DIR . '/junk';
        file_put_contents($junk, '');
        $this->expectException(WrongKeyOrModifiedCiphertextException::class);
        File::decryptFile($junk, self::$TEMP_DIR . '/unjunked', $this->key);
    }

    public function test_decrypt_truncated_ciphertext(): void
    {
        // This tests for issue #115 on GitHub.
        $plaintext_path  = self::$TEMP_DIR . '/plaintext';
        $ciphertext_path = self::$TEMP_DIR . '/ciphertext';
        $truncated_path  = self::$TEMP_DIR . '/truncated';

        file_put_contents($plaintext_path, str_repeat('A', 1024));
        File::encryptFile($plaintext_path, $ciphertext_path, $this->key);

        $ciphertext = file_get_contents($ciphertext_path);
        $truncated  = substr($ciphertext, 0, 64);
        file_put_contents($truncated_path, $truncated);

        $this->expectException(WrongKeyOrModifiedCiphertextException::class);
        File::decryptFile($truncated_path, $plaintext_path, $this->key);
    }

    public function test_encrypt_with_crypto_decrypt_with_file(): void
    {
        $ciphertext_path = self::$TEMP_DIR . '/ciphertext';
        $plaintext_path  = self::$TEMP_DIR . '/plaintext';

        $key        = Key::createNewRandomKey();
        $plaintext  = 'Plaintext!';
        $ciphertext = Crypto::encrypt($plaintext, $key, true);
        file_put_contents($ciphertext_path, $ciphertext);

        File::decryptFile($ciphertext_path, $plaintext_path, $key);

        $plaintext_decrypted = file_get_contents($plaintext_path);
        self::assertSame($plaintext, $plaintext_decrypted);
    }

    public function test_encrypt_with_file_decrypt_with_crypto(): void
    {
        $ciphertext_path = self::$TEMP_DIR . '/ciphertext';
        $plaintext_path  = self::$TEMP_DIR . '/plaintext';

        $key       = Key::createNewRandomKey();
        $plaintext = 'Plaintext!';
        file_put_contents($plaintext_path, $plaintext);
        File::encryptFile($plaintext_path, $ciphertext_path, $key);

        $ciphertext          = file_get_contents($ciphertext_path);
        $plaintext_decrypted = Crypto::decrypt($ciphertext, $key, true);
        self::assertSame($plaintext, $plaintext_decrypted);
    }

    public function test_extra_data(): void
    {
        $src  = self::$FILE_DIR . '/wat-gigantic-duck.jpg';
        $dest = self::$TEMP_DIR . '/err';

        File::encryptFile($src, $dest, $this->key);

        file_put_contents($dest, str_repeat('A', 2048), FILE_APPEND);

        $this->expectException(WrongKeyOrModifiedCiphertextException::class);
        $this->expectExceptionMessage('Integrity check failed.');
        File::decryptFile($dest, $dest . '.jpg', $this->key);
    }

    public function test_file_create_random_key(): void
    {
        $result = Key::createNewRandomKey();
        self::assertInstanceOf('\Ecotone\DataProtection\Encryption\Key', $result);
    }

    public function test_bad_source_path_encrypt(): void
    {
        $this->expectException(IOException::class);
        $this->expectExceptionMessage('No such file or directory');
        File::encryptFile('./i-do-not-exist', 'output-file', $this->key);
    }

    public function test_bad_source_path_decrypt(): void
    {
        $this->expectException(IOException::class);
        $this->expectExceptionMessage('No such file or directory');
        File::decryptFile('./i-do-not-exist', 'output-file', $this->key);
    }

    public function test_bad_source_path_encrypt_with_password(): void
    {
        $this->expectException(IOException::class);
        $this->expectExceptionMessage('No such file or directory');
        File::encryptFileWithPassword('./i-do-not-exist', 'output-file', 'password');
    }

    public function test_bad_source_path_decrypt_with_password(): void
    {
        $this->expectException(IOException::class);
        $this->expectExceptionMessage('No such file or directory');
        File::decryptFileWithPassword('./i-do-not-exist', 'output-file', 'password');
    }

    public function test_bad_destination_path_encrypt(): void
    {
        $src  = self::$FILE_DIR . '/wat-gigantic-duck.jpg';
        $this->expectException(IOException::class);
        $this->expectExceptionMessage('Is a directory');
        File::encryptFile($src, './', $this->key);
    }

    public function test_bad_destination_path_decrypt(): void
    {
        $src  = self::$FILE_DIR . '/wat-gigantic-duck.jpg';
        $this->expectException(IOException::class);
        $this->expectExceptionMessage('Is a directory');
        File::decryptFile($src, './', $this->key);
    }

    public function test_bad_destination_path_encrypt_with_password(): void
    {
        $src  = self::$FILE_DIR . '/wat-gigantic-duck.jpg';
        $this->expectException(IOException::class);
        $this->expectExceptionMessage('Is a directory');
        File::encryptFileWithPassword($src, './', 'password');
    }

    public function test_bad_destination_path_decrypt_with_password(): void
    {
        $src  = self::$FILE_DIR . '/wat-gigantic-duck.jpg';
        $this->expectException(IOException::class);
        $this->expectExceptionMessage('Is a directory');
        File::decryptFileWithPassword($src, './', 'password');
    }

    public function test_non_resource_input_encrypt(): void
    {
        $resource = fopen('php://memory', 'wb');
        $this->expectException(IOException::class);
        $this->expectExceptionMessage('must be a resource');
        File::encryptResource('not a resource', $resource, $this->key);
        fclose($resource);
    }

    public function test_non_resource_output_encrypt(): void
    {
        $resource = fopen('php://memory', 'wb');
        $this->expectException(IOException::class);
        $this->expectExceptionMessage('must be a resource');
        File::encryptResource($resource, 'not a resource', $this->key);
        fclose($resource);
    }

    public function test_non_resource_input_decrypt(): void
    {
        $resource = fopen('php://memory', 'wb');
        $this->expectException(IOException::class);
        $this->expectExceptionMessage('must be a resource');
        File::decryptResource('not a resource', $resource, $this->key);
        fclose($resource);
    }

    public function test_non_resource_output_decrypt(): void
    {
        $resource = fopen('php://memory', 'wb');
        $this->expectException(IOException::class);
        $this->expectExceptionMessage('must be a resource');
        File::decryptResource($resource, 'not a resource', $this->key);
        fclose($resource);
    }

    public function test_non_file_resource_decrypt(): void
    {
        /* This should behave equivalently to an empty file. Calling fstat() on
            stdin returns a result saying it has zero size. */
        $stdin = fopen('php://stdin', 'r');
        $output = fopen('php://memory', 'wb');
        try {
            File::decryptResource($stdin, $output, $this->key);
        } catch (Exception $ex) {
            fclose($output);
            fclose($stdin);
            $this->expectException(WrongKeyOrModifiedCiphertextException::class);
            throw $ex;
        }
    }

    public static function fileToFileProvider(): Generator
    {
        yield 'empty-file' => ['empty-file.txt'];
        yield 'wat-gigantic-duck' => ['wat-gigantic-duck.jpg'];
        yield 'extra-large' => ['big-generated-file'];
    }
}
