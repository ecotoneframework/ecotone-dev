<?php

declare(strict_types=1);

namespace Ecotone\Modelling\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Repository
{
}
