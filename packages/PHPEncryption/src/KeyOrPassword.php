<?php

namespace Ecotone\PHPEncryption;

use Ecotone\PHPEncryption\Exception\EnvironmentIsBrokenException;

use function hash;
use function is_string;

use SensitiveParameter;

/**
 * licence Apache-2.0
 */
final class KeyOrPassword
{
    public const int PBKDF2_ITERATIONS = 100000;
    public const int SECRET_TYPE_KEY = 1;
    public const int SECRET_TYPE_PASSWORD = 2;

    private int $secret_type;
    private Key|string $secret;

    /**
     * Constructor for KeyOrPassword.
     *
     * @param int $secret_type
     * @param mixed $secret (either a Key or a password string)
     * @throws EnvironmentIsBrokenException
     */
    private function __construct(int $secret_type, #[SensitiveParameter] mixed $secret)
    {
        // The constructor is private, so these should never throw.
        if ($secret_type === self::SECRET_TYPE_KEY) {
            Core::ensureTrue($secret instanceof Key);
        } elseif ($secret_type === self::SECRET_TYPE_PASSWORD) {
            Core::ensureTrue(is_string($secret));
        } else {
            throw new EnvironmentIsBrokenException('Bad secret type.');
        }
        $this->secret_type = $secret_type;
        $this->secret = $secret;
    }

    /**
     * Initializes an instance of KeyOrPassword from a key.
     *
     * @throws EnvironmentIsBrokenException
     */
    public static function createFromKey(Key $key): self
    {
        return new KeyOrPassword(self::SECRET_TYPE_KEY, $key);
    }

    /**
     * Initializes an instance of KeyOrPassword from a password.
     *
     * @throws EnvironmentIsBrokenException
     */
    public static function createFromPassword(#[SensitiveParameter] string $password): self
    {
        return new KeyOrPassword(self::SECRET_TYPE_PASSWORD, $password);
    }

    /**
     * Derives authentication and encryption keys from the secret, using a slow key derivation function if the secret is a password.
     *
     * @throws EnvironmentIsBrokenException
     */
    public function deriveKeys(string $salt): DerivedKeys
    {
        Core::ensureTrue(Core::strlen($salt) === Core::SALT_BYTE_SIZE, 'Bad salt.');

        if ($this->secret_type === self::SECRET_TYPE_KEY) {
            Core::ensureTrue($this->secret instanceof Key);

            $authenticationKey = Core::HKDF(
                Core::HASH_FUNCTION_NAME,
                $this->secret->getRawBytes(),
                Core::KEY_BYTE_SIZE,
                Core::AUTHENTICATION_INFO_STRING,
                $salt
            );

            $encryptionKey = Core::HKDF(
                Core::HASH_FUNCTION_NAME,
                $this->secret->getRawBytes(),
                Core::KEY_BYTE_SIZE,
                Core::ENCRYPTION_INFO_STRING,
                $salt
            );

            return new DerivedKeys($authenticationKey, $encryptionKey);
        }

        if ($this->secret_type === self::SECRET_TYPE_PASSWORD) {
            Core::ensureTrue(is_string($this->secret));
            /* Our PBKDF2 polyfill is vulnerable to a DoS attack documented in
             * GitHub issue #230. The fix is to pre-hash the password to ensure
             * it is short. We do the prehashing here instead of in pbkdf2() so
             * that pbkdf2() still computes the function as defined by the
             * standard. */

            $prehash = hash(Core::HASH_FUNCTION_NAME, $this->secret, true);

            $prekey = Core::pbkdf2(
                Core::HASH_FUNCTION_NAME,
                $prehash,
                $salt,
                self::PBKDF2_ITERATIONS,
                Core::KEY_BYTE_SIZE,
                true
            );
            $authenticationKey = Core::HKDF(
                Core::HASH_FUNCTION_NAME,
                $prekey,
                Core::KEY_BYTE_SIZE,
                Core::AUTHENTICATION_INFO_STRING,
                $salt
            );
            /* Note the cryptographic re-use of $salt here. */
            $encryptionKey = Core::HKDF(
                Core::HASH_FUNCTION_NAME,
                $prekey,
                Core::KEY_BYTE_SIZE,
                Core::ENCRYPTION_INFO_STRING,
                $salt
            );

            return new DerivedKeys($authenticationKey, $encryptionKey);
        }

        throw new EnvironmentIsBrokenException('Bad secret type.');
    }
}
