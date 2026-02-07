<?php

/**
 * licence Enterprise
 */

namespace Ecotone\DataProtection\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER)]
class Sensitive
{
}
