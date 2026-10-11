<?php

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

namespace Ecotone\DataProtection\Configuration;

use Ecotone\Api\DataProtection\DataProtectionConfiguration;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\Assert;

final readonly class DataProtectorConfig
{
    /**
     * @param array<string> $sensitiveProperties
     */
    public function __construct(
        public Type $supportedType,
        public ?string $encryptionKey,
        public array $sensitiveProperties,
        public array $scalarProperties,
        public array $sensitivePropertyNames,
    ) {
        Assert::allStrings($this->sensitiveProperties, 'Sensitive Properties should be array of strings');
        Assert::allStrings($this->scalarProperties, 'Scalar Properties should be array of strings');
        Assert::allStrings($this->sensitivePropertyNames, 'Sensitive Properties custom names should be array of strings');
    }

    public function encryptionKeyName(DataProtectionConfiguration $dataProtectionConfiguration): string
    {
        return $dataProtectionConfiguration->keyName($this->encryptionKey);
    }
}
