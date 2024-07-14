<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ORM\Person;

/**
 * licence Apache-2.0
 */
class RegisterPerson
{
    public function __construct(private int $personId, private string $name, private bool $exception = false)
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

    public function isException(): bool
    {
        return $this->exception;
    }
}
