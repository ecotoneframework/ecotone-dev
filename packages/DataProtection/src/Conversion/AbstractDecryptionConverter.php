<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Conversion;

use Ecotone\DataProtection\Encryption\Crypto;

/**
 * licence Enterprise
 */
abstract class AbstractDecryptionConverter extends AbstractDataProtectionConverter
{
    protected function decrypt(array $data): array
    {
        foreach ($this->sensitiveProperties as $property) {
            $propertyKey = $this->sensitivePropertyNames[$property] ?? $property;
            if (! array_key_exists($propertyKey, $data)) {
                continue;
            }

            $data[$propertyKey] = Crypto::decrypt($data[$propertyKey], $this->encryptionKey);

            if (! in_array($propertyKey, $this->scalarProperties, true)) {
                $data[$propertyKey] = json_decode($data[$propertyKey], true);
            }
        }

        return $data;
    }
}
