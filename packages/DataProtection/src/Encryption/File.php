<?php

namespace Ecotone\DataProtection\Encryption;

use function array_shift;

use Ecotone\DataProtection\Encryption\Exception\CryptoException;
use Ecotone\DataProtection\Encryption\Exception\EnvironmentIsBrokenException;
use Ecotone\DataProtection\Encryption\Exception\IOException;
use Ecotone\DataProtection\Encryption\Exception\WrongKeyOrModifiedCiphertextException;
use SensitiveParameter;

use function fclose;
use function feof;
use function fopen;
use function fread;
use function fseek;
use function fstat;
use function ftell;
use function fwrite;
use function hash_copy;
use function hash_final;
use function hash_init;
use function hash_update;
use function is_callable;
use function is_int;
use function is_object;
use function is_resource;
use function is_string;
use function openssl_decrypt;
use function openssl_encrypt;
use function stream_set_read_buffer;
use function stream_set_write_buffer;

/**
 * licence Apache-2.0
 */
final class File
{
    /**
     * Encrypts the input file, saving the ciphertext to the output file.
     *
     * @throws CryptoException|EnvironmentIsBrokenException|IOException|WrongKeyOrModifiedCiphertextException
     */
    public static function encryptFile(string $inputFilename, string $outputFilename, Key $key): void
    {
        self::encryptFileInternal($inputFilename, $outputFilename, KeyOrPassword::createFromKey($key));
    }

    /**
     * Encrypts a file with a password, using a slow key derivation function to make password cracking more expensive.
     *
     * @throws CryptoException|EnvironmentIsBrokenException|IOException|WrongKeyOrModifiedCiphertextException
     */
    public static function encryptFileWithPassword(string $inputFilename, string $outputFilename, #[SensitiveParameter] string $password): void
    {
        self::encryptFileInternal(
            $inputFilename,
            $outputFilename,
            KeyOrPassword::createFromPassword($password)
        );
    }

    /**
     * Decrypts the input file, saving the plaintext to the output file.
     *
     * @throws CryptoException|EnvironmentIsBrokenException|IOException|WrongKeyOrModifiedCiphertextException
     */
    public static function decryptFile(string $inputFilename, string $outputFilename, Key $key): void
    {
        self::decryptFileInternal($inputFilename, $outputFilename, KeyOrPassword::createFromKey($key));
    }

    /**
     * Decrypts a file with a password, using a slow key derivation function to make password cracking more expensive.
     *
     * @throws CryptoException|EnvironmentIsBrokenException|IOException|WrongKeyOrModifiedCiphertextException
     */
    public static function decryptFileWithPassword(string $inputFilename, string $outputFilename, #[SensitiveParameter] string $password): void
    {
        self::decryptFileInternal($inputFilename, $outputFilename, KeyOrPassword::createFromPassword($password));
    }

    /**
     * Takes two resource handles and encrypts the contents of the first, writing the ciphertext into the second.
     *
     * @throws CryptoException|EnvironmentIsBrokenException|IOException|WrongKeyOrModifiedCiphertextException
     */
    public static function encryptResource($inputHandle, $outputHandle, Key $key): void
    {
        self::encryptResourceInternal($inputHandle, $outputHandle, KeyOrPassword::createFromKey($key));
    }

    /**
     * Encrypts the contents of one resource handle into another with a password, using a slow key derivation function to make password cracking more expensive.
     *
     * @throws CryptoException|EnvironmentIsBrokenException|IOException|WrongKeyOrModifiedCiphertextException
     */
    public static function encryptResourceWithPassword($inputHandle, $outputHandle, #[SensitiveParameter] string $password): void
    {
        self::encryptResourceInternal($inputHandle, $outputHandle, KeyOrPassword::createFromPassword($password));
    }

    /**
     * Takes two resource handles and decrypts the contents of the first, writing the plaintext into the second.
     *
     * @throws CryptoException|EnvironmentIsBrokenException|IOException|WrongKeyOrModifiedCiphertextException
     */
    public static function decryptResource($inputHandle, $outputHandle, Key $key): void
    {
        self::decryptResourceInternal($inputHandle, $outputHandle, KeyOrPassword::createFromKey($key));
    }

    /**
     * Decrypts the contents of one resource into another with a password, using a slow key derivation function to make password cracking more expensive.
     *
     * @throws CryptoException|EnvironmentIsBrokenException|IOException|WrongKeyOrModifiedCiphertextException
     */
    public static function decryptResourceWithPassword($inputHandle, $outputHandle, #[SensitiveParameter] string $password): void
    {
        self::decryptResourceInternal($inputHandle, $outputHandle, KeyOrPassword::createFromPassword($password));
    }

    /**
     * Encrypts a file with either a key or a password.
     *
     * @throws CryptoException|EnvironmentIsBrokenException|IOException|WrongKeyOrModifiedCiphertextException
     */
    private static function encryptFileInternal(string $inputFilename, string $outputFilename, KeyOrPassword $secret): void
    {
        if (file_exists($inputFilename) && file_exists($outputFilename) && realpath($inputFilename) === realpath($outputFilename)) {
            throw new IOException('Input and output filenames must be different.');
        }

        /* Open the input file. */
        self::removePHPUnitErrorHandler();
        $if = @fopen($inputFilename, 'rb');
        self::restorePHPUnitErrorHandler();

        if ($if === false) {
            throw new IOException('Cannot open input file for encrypting: ' . self::getLastErrorMessage());
        }
        if (is_callable('\\stream_set_read_buffer')) {
            /* This call can fail, but the only consequence is performance. */
            stream_set_read_buffer($if, 0);
        }

        /* Open the output file. */
        self::removePHPUnitErrorHandler();
        $of = @fopen($outputFilename, 'wb');
        self::restorePHPUnitErrorHandler();
        if ($of === false) {
            fclose($if);
            throw new IOException('Cannot open output file for encrypting: ' . self::getLastErrorMessage());
        }

        if (is_callable('\\stream_set_write_buffer')) {
            /* This call can fail, but the only consequence is performance. */
            stream_set_write_buffer($of, 0);
        }

        /* Perform the encryption. */
        try {
            self::encryptResourceInternal($if, $of, $secret);
        } catch (CryptoException $ex) {
            fclose($if);
            fclose($of);

            throw $ex;
        }

        /* Close the input file. */
        if (fclose($if) === false) {
            fclose($of);
            throw new IOException('Cannot close input file after encrypting');
        }

        /* Close the output file. */
        if (fclose($of) === false) {
            throw new IOException('Cannot close output file after encrypting');
        }
    }

    /**
     * Decrypts a file with either a key or a password.
     *
     * @throws CryptoException|EnvironmentIsBrokenException|IOException|WrongKeyOrModifiedCiphertextException
     */
    private static function decryptFileInternal(string $inputFilename, string $outputFilename, KeyOrPassword $secret): void
    {
        if (file_exists($inputFilename) && file_exists($outputFilename) && realpath($inputFilename) === realpath($outputFilename)) {
            throw new IOException('Input and output filenames must be different.');
        }

        /* Open the input file. */
        self::removePHPUnitErrorHandler();
        $if = @fopen($inputFilename, 'rb');
        self::restorePHPUnitErrorHandler();
        if ($if === false) {
            throw new IOException('Cannot open input file for decrypting: ' . self::getLastErrorMessage());
        }

        if (is_callable('\\stream_set_read_buffer')) {
            /* This call can fail, but the only consequence is performance. */
            stream_set_read_buffer($if, 0);
        }

        /* Open the output file. */
        self::removePHPUnitErrorHandler();
        $of = @fopen($outputFilename, 'wb');
        self::restorePHPUnitErrorHandler();
        if ($of === false) {
            fclose($if);
            throw new IOException('Cannot open output file for decrypting: ' . self::getLastErrorMessage());
        }

        if (is_callable('\\stream_set_write_buffer')) {
            /* This call can fail, but the only consequence is performance. */
            stream_set_write_buffer($of, 0);
        }

        /* Perform the decryption. */
        try {
            self::decryptResourceInternal($if, $of, $secret);
        } catch (CryptoException $ex) {
            fclose($if);
            fclose($of);

            throw $ex;
        }

        /* Close the input file. */
        if (fclose($if) === false) {
            fclose($of);
            throw new IOException('Cannot close input file after decrypting');
        }

        /* Close the output file. */
        if (fclose($of) === false) {
            throw new IOException('Cannot close output file after decrypting');
        }
    }

    /**
     * Encrypts a resource with either a key or a password.
     *
     * Fixes erroneous errors caused by PHP 7.2 switching the return value of hash_init from a resource to a HashContext.
     *
     * @throws IOException|EnvironmentIsBrokenException|CryptoException
     *
     * @psalm-suppress PossiblyInvalidArgument
     */
    private static function encryptResourceInternal($inputHandle, $outputHandle, KeyOrPassword $secret): void
    {
        if (! is_resource($inputHandle)) {
            throw new IOException('Input handle must be a resource!');
        }

        if (! is_resource($outputHandle)) {
            throw new IOException('Output handle must be a resource!');
        }

        $inputStat = fstat($inputHandle);
        $inputSize = $inputStat['size'];

        $file_salt = Core::secureRandom(Core::SALT_BYTE_SIZE);
        $keys = $secret->deriveKeys($file_salt);
        $ivsize = Core::BLOCK_BYTE_SIZE;
        $iv     = Core::secureRandom($ivsize);

        // Initialize a streaming HMAC state.
        $hmac = hash_init(Core::HASH_FUNCTION_NAME, HASH_HMAC, $keys->authenticationKey);
        Core::ensureTrue(is_resource($hmac) || is_object($hmac), 'Cannot initialize a hash context');

        // Write the header, salt, and IV.
        self::writeBytes(
            $outputHandle,
            Core::CURRENT_VERSION . $file_salt . $iv,
            Core::HEADER_VERSION_SIZE + Core::SALT_BYTE_SIZE + $ivsize
        );

        // Add the header, salt, and IV to the HMAC.
        hash_update($hmac, Core::CURRENT_VERSION);
        hash_update($hmac, $file_salt);
        hash_update($hmac, $iv);

        // $thisIv will be incremented after each call to the encryption.
        $thisIv = $iv;

        // How many blocks do we encrypt at a time? We increment by this value.
        $inc = (int) (Core::BUFFER_BYTE_SIZE / Core::BLOCK_BYTE_SIZE);

        // Loop until we reach the end of the input file. */
        $at_file_end = false;
        while (! (feof($inputHandle) || $at_file_end)) {
            // Find out if we can read a full buffer, or only a partial one.
            $pos = ftell($inputHandle);
            if (! is_int($pos)) {
                throw new IOException('Could not get current position in input file during encryption');
            }
            if ($pos + Core::BUFFER_BYTE_SIZE >= $inputSize) {
                // We're at the end of the file, so we need to break out of the loop.
                $at_file_end = true;
                $read = self::readBytes($inputHandle, $inputSize - $pos);
            } else {
                $read = self::readBytes($inputHandle, Core::BUFFER_BYTE_SIZE);
            }

            // Encrypt this buffer.
            /** @var string */
            $encrypted = openssl_encrypt($read, Core::CIPHER_METHOD, $keys->encryptionKey, OPENSSL_RAW_DATA, $thisIv);

            Core::ensureTrue(is_string($encrypted), 'OpenSSL encryption error');

            // Write this buffer's ciphertext.
            self::writeBytes($outputHandle, $encrypted, Core::strlen($encrypted));
            // Add this buffer's ciphertext to the HMAC.
            hash_update($hmac, $encrypted);

            // Increment the counter by the number of blocks in a buffer.
            $thisIv = Core::incrementCounter($thisIv, $inc);
            // WARNING: Usually, unless the file is a multiple of the buffer size, $thisIv will contain an incorrect value here on the last iteration of this loop.
        }

        // Get the HMAC and append it to the ciphertext.
        $final_mac = hash_final($hmac, true);
        self::writeBytes($outputHandle, $final_mac, Core::MAC_BYTE_SIZE);
    }

    /**
     * Decrypts a file-backed resource with either a key or a password.
     *
     * Fixes erroneous errors caused by PHP 7.2 switching the return value of hash_init from a resource to a HashContext.
     *
     * @throws IOException|WrongKeyOrModifiedCiphertextException|EnvironmentIsBrokenException|CryptoException
     *
     * @psalm-suppress PossiblyInvalidArgument
     */
    public static function decryptResourceInternal($inputHandle, $outputHandle, KeyOrPassword $secret): void
    {
        if (! is_resource($inputHandle)) {
            throw new IOException('Input handle must be a resource!');
        }
        if (! is_resource($outputHandle)) {
            throw new IOException('Output handle must be a resource!');
        }

        // Make sure the file is big enough for all the reads we need to do.
        $stat = fstat($inputHandle);
        if ($stat['size'] < Core::MINIMUM_CIPHERTEXT_SIZE) {
            throw new WrongKeyOrModifiedCiphertextException('Input file is too small to have been created by this library.');
        }

        // Check the version header.
        $header = self::readBytes($inputHandle, Core::HEADER_VERSION_SIZE);
        if ($header !== Core::CURRENT_VERSION) {
            throw new WrongKeyOrModifiedCiphertextException('Bad version header.');
        }

        // Get the salt.
        $file_salt = self::readBytes($inputHandle, Core::SALT_BYTE_SIZE);

        // Get the IV.
        $ivsize = Core::BLOCK_BYTE_SIZE;
        $iv     = self::readBytes($inputHandle, $ivsize);

        // Derive the authentication and encryption keys.
        $keys = $secret->deriveKeys($file_salt);
        // We'll store the MAC of each buffer-sized chunk as we verify the actual MAC, so that we can check them again when decrypting.
        $macs = [];

        // $thisIv will be incremented after each call to the decryption.
        $thisIv = $iv;

        // How many blocks do we encrypt at a time? We increment by this value.
        $inc = (int) (Core::BUFFER_BYTE_SIZE / Core::BLOCK_BYTE_SIZE);

        // Get the HMAC.
        if (fseek($inputHandle, (-1 * Core::MAC_BYTE_SIZE), SEEK_END) === -1) {
            throw new IOException('Cannot seek to beginning of MAC within input file');
        }

        // Get the position of the last byte in the actual ciphertext.
        $cipher_end = ftell($inputHandle);
        if (! is_int($cipher_end)) {
            throw new IOException('Cannot read input file');
        }
        // We have the position of the first byte of the HMAC. Go back by one.
        --$cipher_end;

        // Read the HMAC.
        $stored_mac = self::readBytes($inputHandle, Core::MAC_BYTE_SIZE);

        // Initialize a streaming HMAC state.
        $hmac = hash_init(Core::HASH_FUNCTION_NAME, HASH_HMAC, $keys->authenticationKey);
        Core::ensureTrue(is_resource($hmac) || is_object($hmac), 'Cannot initialize a hash context');

        // Reset file pointer to the beginning of the file after the header
        if (fseek($inputHandle, Core::HEADER_VERSION_SIZE, SEEK_SET) === -1) {
            throw new IOException('Cannot read seek within input file');
        }

        // Seek to the start of the actual ciphertext.
        if (fseek($inputHandle, Core::SALT_BYTE_SIZE + $ivsize, SEEK_CUR) === -1) {
            throw new IOException('Cannot seek input file to beginning of ciphertext');
        }

        // PASS #1: Calculating the HMAC.

        hash_update($hmac, $header);
        hash_update($hmac, $file_salt);
        hash_update($hmac, $iv);
        $hmac2 = hash_copy($hmac);

        $break = false;
        while (! $break) {
            $pos = ftell($inputHandle);
            if (! is_int($pos)) {
                throw new IOException('Could not get current position in input file during decryption');
            }

            // Read the next buffer-sized chunk (or less).
            if ($pos + Core::BUFFER_BYTE_SIZE >= $cipher_end) {
                $break = true;
                $read  = self::readBytes(
                    $inputHandle,
                    $cipher_end - $pos + 1
                );
            } else {
                $read = self::readBytes(
                    $inputHandle,
                    Core::BUFFER_BYTE_SIZE
                );
            }

            // Update the HMAC.
            hash_update($hmac, $read);

            // Remember this buffer-sized chunk's HMAC.
            $chunk_mac = hash_copy($hmac);
            Core::ensureTrue(is_resource($chunk_mac) || is_object($chunk_mac), 'Cannot duplicate a hash context');
            $macs [] = hash_final($chunk_mac);
        }

        // Get the final HMAC, which should match the stored one.
        $final_mac = hash_final($hmac, true);

        // Verify the HMAC.
        if (! hash_equals($final_mac, $stored_mac)) {
            throw new WrongKeyOrModifiedCiphertextException('Integrity check failed.');
        }

        // PASS #2: Decrypt and write output.

        // Rewind to the start of the actual ciphertext.
        if (fseek($inputHandle, Core::SALT_BYTE_SIZE + $ivsize + Core::HEADER_VERSION_SIZE, SEEK_SET) === -1) {
            throw new IOException('Could not move the input file pointer during decryption');
        }

        $at_file_end = false;
        while (! $at_file_end) {
            $pos = ftell($inputHandle);
            if (! is_int($pos)) {
                throw new IOException('Could not get current position in input file during decryption');
            }

            /* Read the next buffer-sized chunk (or less). */
            if ($pos + Core::BUFFER_BYTE_SIZE >= $cipher_end) {
                $at_file_end = true;
                $read   = self::readBytes(
                    $inputHandle,
                    $cipher_end - $pos + 1
                );
            } else {
                $read = self::readBytes(
                    $inputHandle,
                    Core::BUFFER_BYTE_SIZE
                );
            }

            /* Recalculate the MAC (so far) and compare it with the one we
             * remembered from pass #1 to ensure attackers didn't change the
             * ciphertext after MAC verification. */
            hash_update($hmac2, $read);
            $calc_mac = hash_copy($hmac2);
            Core::ensureTrue(is_resource($calc_mac) || is_object($calc_mac), 'Cannot duplicate a hash context');
            $calc = hash_final($calc_mac);

            if (empty($macs)) {
                throw new WrongKeyOrModifiedCiphertextException('File was modified after MAC verification');
            }

            if (! hash_equals(array_shift($macs), $calc)) {
                throw new WrongKeyOrModifiedCiphertextException('File was modified after MAC verification');
            }

            // Decrypt this buffer-sized chunk.
            $decrypted = openssl_decrypt($read, Core::CIPHER_METHOD, $keys->encryptionKey, OPENSSL_RAW_DATA, $thisIv);
            Core::ensureTrue(is_string($decrypted), 'OpenSSL decryption error');

            // Write the plaintext to the output file.
            self::writeBytes($outputHandle, $decrypted, Core::strlen($decrypted));

            // Increment the IV by the amount of blocks in a buffer.
            $thisIv = Core::incrementCounter($thisIv, $inc);
            // WARNING: Usually, unless the file is a multiple of the buffer size, $thisIv will contain an incorrect value here on the last iteration of this loop.
        }
    }

    /**
     * Read from a stream; prevent partial reads.
     *
     * @throws EnvironmentIsBrokenException|IOException
     */
    public static function readBytes($stream, int $num_bytes): string
    {
        Core::ensureTrue($num_bytes >= 0, 'Tried to read less than 0 bytes');

        if ($num_bytes === 0) {
            return '';
        }

        $buf = '';
        $remaining = $num_bytes;
        while ($remaining > 0 && ! feof($stream)) {
            $read = fread($stream, $remaining);
            if (! is_string($read)) {
                throw new IOException('Could not read from the file');
            }
            $buf .= $read;
            $remaining -= Core::strlen($read);
        }
        if (Core::strlen($buf) !== $num_bytes) {
            throw new IOException('Tried to read past the end of the file');
        }

        return $buf;
    }

    /**
     * Write to a stream; prevents partial writes.
     *
     * @throws EnvironmentIsBrokenException|IOException
     */
    public static function writeBytes($stream, string $buf, ?int $num_bytes = null): ?int
    {
        $bufSize = Core::strlen($buf);
        if ($num_bytes === null) {
            $num_bytes = $bufSize;
        }
        if ($num_bytes > $bufSize) {
            throw new IOException('Trying to write more bytes than the buffer contains.');
        }
        if ($num_bytes < 0) {
            throw new IOException('Tried to write less than 0 bytes');
        }
        $remaining = $num_bytes;
        while ($remaining > 0) {
            $written = fwrite($stream, $buf, $remaining);
            if (! is_int($written)) {
                throw new IOException('Could not write to the file');
            }
            $buf = (string) Core::substr($buf, $written, null);
            $remaining -= $written;
        }

        return $num_bytes;
    }

    /**
     * Returns the last PHP error's or warning's message string.
     */
    private static function getLastErrorMessage(): string
    {
        $error = error_get_last();
        if ($error === null) {
            return '[no PHP error, or you have a custom error handler set]';
        }

        return $error['message'];
    }

    /**
     * PHPUnit sets an error handler, which prevents getLastErrorMessage() from working,
     * because error_get_last does not work when custom handlers are set.
     *
     * This is a workaround, which should be a no-op in production deployments, to make
     * getLastErrorMessage() return the error messages that the PHPUnit tests expect.
     *
     * If, in a production deployment, a custom error handler is set, the exception
     * handling will still work as usual, but the error messages will be confusing.
     */
    private static function removePHPUnitErrorHandler(): void
    {
        if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__')) {
            set_error_handler(null);
        }
    }

    /**
     * Undoes what removePHPUnitErrorHandler did.
     */
    private static function restorePHPUnitErrorHandler(): void
    {
        if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__')) {
            restore_error_handler();
        }
    }
}
