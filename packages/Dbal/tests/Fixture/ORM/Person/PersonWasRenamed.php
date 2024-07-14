<?php

namespace Test\Ecotone\Dbal\Fixture\ORM\Person;

/**
 * licence Apache-2.0
 */
class PersonWasRenamed
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
