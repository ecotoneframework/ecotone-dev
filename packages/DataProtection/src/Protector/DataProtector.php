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

    public function encrypt(string $data): string
    {
        $data = json_decode($data, true);

        foreach ($this->sensitiveProperties as $property) {
            if (! array_key_exists($property, $data)) {
                continue;
            }

            if (! in_array($property, $this->scalarProperties, true)) {
                $data[$property] = json_encode($data[$property]);
            }

            $data[$property] = Crypto::encrypt($data[$property], $this->encryptionKey);
        }

        return json_encode($data);
    }

    public function decrypt(string $data): string
    {
        $data = json_decode($data, true);

        foreach ($this->sensitiveProperties as $property) {
            if (! array_key_exists($property, $data)) {
                continue;
            }

            $data[$property] = Crypto::decrypt($data[$property], $this->encryptionKey);

            if (! in_array($property, $this->scalarProperties, true)) {
                $data[$property] = json_decode($data[$property], true);
            }
        }

        return json_encode($data);
    }
}
