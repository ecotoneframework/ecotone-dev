<?php

namespace Test\Ecotone\Messaging\Fixture\Handler\Property\Extra;

use Ecotone\Api\Identifier;

trait PrivatePropertyTrait
{
    #[Identifier]
    private ?ExtraObject $property;
}
