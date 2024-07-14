<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

/**
 * licence Apache-2.0
 */
final class PersonNameNormalizer
{
    public function normalize(PersonName $name): string
    {
        return $name->toLowerCase();
    }
}
