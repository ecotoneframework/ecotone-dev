<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

final class PersonNameNormalizer
{
    public function normalize(PersonName $name): string
    {
        return $name->toLowerCase();
    }
}
