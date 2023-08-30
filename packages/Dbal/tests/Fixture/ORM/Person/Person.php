<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ORM\Person;

use Doctrine\ORM\Mapping as ORM;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\WithEvents;
use RuntimeException;

#[ORM\Entity]
#[ORM\Table(name: 'persons')]
#[Aggregate]
class Person
{
    use WithEvents;

    public const RENAME_COMMAND = 'person.rename';

    #[ORM\Id]
    #[ORM\Column(name: 'person_id', type: 'integer')]
    #[Identifier]
    private int $personId;

    #[ORM\Column(name: 'name', type: 'string')]
    private string $name;

    private function __construct(int $personId, string $name)
    {
        $this->personId = $personId;
        $this->name = $name;

        $this->recordThat(new PersonRegistered($personId, $name));
    }

    #[CommandHandler]
    public static function register(RegisterPerson $command): static
    {
        $person = new self($command->getPersonId(), $command->getName());
        if ($command->isException()) {
            throw new RuntimeException('Exception');
        }

        return $person;
    }

    #[CommandHandler(self::RENAME_COMMAND)]
    public function changeName(string $name): void
    {
        $this->name = $name;

        $this->recordThat(new PersonWasRenamed($this->personId, $name));
    }

    #[QueryHandler('person.getName')]
    public function getName(): string
    {
        return $this->name;
    }
}
