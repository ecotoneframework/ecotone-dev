<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

final class PersonNameDTO
{
    public function __construct(private int $personId, private string $name)
    {

    }

    public function getPersonId(): int
    {
        return $this->personId;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
