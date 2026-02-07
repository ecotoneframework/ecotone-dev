<?php

/**
 * licence Enterprise
 */

namespace Ecotone\DataProtection\Configuration;

use Ecotone\Messaging\Support\Assert;

final readonly class ObfuscatorConfig
{
    /**
     * @param array<string> $sensitiveHeaders
     */
    public function __construct(
        public ?string $encryptionKey,
        public bool $isPayloadSensitive,
        public array $sensitiveHeaders,
    ) {
        Assert::allStrings($this->sensitiveHeaders, 'Sensitive Headers should be array of strings');
    }

    public function encryptionKeyName(DataProtectionConfiguration $dataProtectionConfiguration): string
    {
        return $dataProtectionConfiguration->keyName($this->encryptionKey);
    }
}
