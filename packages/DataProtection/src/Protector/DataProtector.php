<?php

/**
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\DataProtection\Protector;

use Ecotone\DataProtection\Encryption\Crypto;
use Ecotone\DataProtection\Encryption\Key;

readonly class DataProtector
{
    public function __construct(
        private Key $encryptionKey,
        private array $sensitiveProperties,
        private array $scalarProperties,
    ) {
    }

    public function encrypt(string $source): string
    {
        $source = json_decode($source, true);

        foreach ($this->sensitiveProperties as $property) {
            if (! array_key_exists($property, $source)) {
                continue;
            }

            if (! in_array($property, $this->scalarProperties, true)) {
                $source[$property] = json_encode($source[$property]);
            }

            $source[$property] = base64_encode(Crypto::encrypt($source[$property], $this->encryptionKey));
        }

        return json_encode($source);
    }

    public function decrypt(string $source): string
    {
        $source = json_decode($source, true);

        foreach ($this->sensitiveProperties as $property) {
            if (! array_key_exists($property, $source)) {
                continue;
            }

            $source[$property] = Crypto::decrypt(base64_decode($source[$property]), $this->encryptionKey);

            if (! in_array($property, $this->scalarProperties, true)) {
                $source[$property] = json_decode($source[$property], true);
            }
        }

        return json_encode($source);
    }
}
