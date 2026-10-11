<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Conversion;

use Ecotone\DataProtection\Encryption\Crypto;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
