<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

/**
 * licence Apache-2.0
 */
final class PersonId
{
    public function __construct(private string $id)
    {

    }

    public function __toString(): string
    {
        return $this->id;
    }
}
