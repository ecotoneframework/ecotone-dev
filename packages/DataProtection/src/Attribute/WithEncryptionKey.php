<?php

/**
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\DataProtection\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER)]
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
