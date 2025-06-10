<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\IdentifierMapping\AttributeMapping;

use Ecotone\Messaging\Attribute\Converter;
use Test\Ecotone\Modelling\Fixture\IdentifierMapping\AttributeMapping\ChangeStatus;

final class ChangeStatusConverter
{
    #[Converter]
    public function from(ChangeStatus $changeStatus): array
    {
        throw new \RuntimeException('Should not be called');
    }
}