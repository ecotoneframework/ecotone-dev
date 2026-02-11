<?php

/**
 * licence Enterprise
 */

namespace Ecotone\DataProtection\Protector;

use Ecotone\DataProtection\Configuration\DataProtectionConfiguration;
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
    ) {
        Assert::allStrings($this->sensitiveProperties, 'Sensitive Properties should be array of strings');
        Assert::allStrings($this->scalarProperties, 'Scalar Properties should be array of strings');
    }

    public function encryptionKeyName(DataProtectionConfiguration $dataProtectionConfiguration): string
    {
        return $dataProtectionConfiguration->keyName($this->encryptionKey);
    }
}
