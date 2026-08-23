<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DocumentStoreAggregate;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\QueryHandler;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class Person
{
    #[Identifier]
    private int $personId;
    private string $name;

    private function __construct(int $personId, string $name)
    {
        $this->personId = $personId;
        $this->name = $name;
    }

    #[CommandHandler]
    public static function register(RegisterPerson $command): static
    {
        return new self($command->getPersonId(), $command->getName());
    }

    #[QueryHandler('person.getName')]
    public function getName(): string
    {
        return $this->name;
    }

    public function getPersonId(): int
    {
        return $this->personId;
    }
}
