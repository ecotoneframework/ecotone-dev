<?php

/**
 * licence Enterprise
 */

namespace Ecotone\Api\DataProtection;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
readonly class Sensitive
{
    public function __construct(public string $sensitiveName = '')
    {
    }
}
