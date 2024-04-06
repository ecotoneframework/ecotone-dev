<?php

namespace Ecotone\Modelling\Attribute;

use Attribute;
use Ecotone\Messaging\Support\Assert;

#[Attribute(Attribute::TARGET_METHOD)]
final class IdentifierMethod extends AggregateIdentifierMethod
{

}
