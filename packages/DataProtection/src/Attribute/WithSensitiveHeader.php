<?php

/**
 * licence Enterprise
 */

namespace Ecotone\DataProtection\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class WithSensitiveHeader
{
    public function __construct(public string $header)
    {
    }
}
