<?php

declare(strict_types=1);

namespace Ecotone\Modelling\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Version extends AggregateVersion
{
}
