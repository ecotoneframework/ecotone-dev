<?php

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
declare(strict_types=1);

namespace Ecotone\Api\DataProtection;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER)]
class WithEncryptionKey
{
    public function __construct(private ?string $encryptionKey = null)
    {
    }

    public function encryptionKey(): ?string
    {
        return $this->encryptionKey;
    }
}
