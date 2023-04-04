<?php

namespace Ecotone\Modelling\Attribute;

use Attribute;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\AggregateMessage;

#[Attribute(Attribute::TARGET_PROPERTY|Attribute::TARGET_PARAMETER)]
class SagaIdentifier extends AggregateIdentifier
{

}
