<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Conversion;

use Ecotone\DataProtection\Encryption\Crypto;

/**
 * licence Enterprise
 */
abstract class AbstractEncryptionConverter extends AbstractDataProtectionConverter
{
    protected function encrypt(array $data): array
    {
        foreach ($this->sensitiveProperties as $property) {
            $propertyKey = $this->sensitivePropertyNames[$property] ?? $property;
            if (! array_key_exists($propertyKey, $data)) {
                continue;
            }

            if (! in_array($propertyKey, $this->scalarProperties, true)) {
                $data[$propertyKey] = json_encode($data[$propertyKey]);
            }

            $data[$propertyKey] = Crypto::encrypt($data[$propertyKey], $this->encryptionKey);
        }

        return $data;
    }
}
