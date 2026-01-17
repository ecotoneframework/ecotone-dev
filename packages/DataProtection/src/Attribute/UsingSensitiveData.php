<?php

namespace Ecotone\DataProtection\Attribute;

use Attribute;
use Ecotone\Messaging\Support\Assert;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class UsingSensitiveData
{
    public function __construct(private ?string $encryptionKeyName = null)
    {
    }

    public function encryptionKeyName(): ?string
    {
        return $this->encryptionKeyName;
    }
}
