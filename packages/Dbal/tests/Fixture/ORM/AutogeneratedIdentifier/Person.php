<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ORM\AutogeneratedIdentifier;

use Doctrine\ORM\Mapping as ORM;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\WithEvents;
use Test\Ecotone\Dbal\Fixture\ORM\Person\PersonWasRenamed;

#[ORM\Entity]
#[ORM\Table(name: 'persons')]
#[Aggregate]
/**
 * licence Apache-2.0
 */
class Person
{
    use WithEvents;

    public const RENAME_COMMAND = 'person.rename';
    public const REGISTER_COMMAND = 'person.register';

    #[ORM\Id()]
    #[ORM\Column(name: 'person_id', type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'person_id_seq')]
    #[Identifier]
    private ?int $personId = null;

    #[ORM\Column(name: 'name', type: 'string')]
    private string $name;

    /**
     * @var array<string>
     */
    #[ORM\Column(name: 'roles', type: 'json')]
    public array $roles = [];

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    #[CommandHandler(self::REGISTER_COMMAND)]
    public static function register(string $name): static
    {
        $person = new self($name);

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

    #[QueryHandler('person.getRoles')]
    public function getRoles(): array
    {
        return $this->roles;
    }

    #[QueryHandler('person.byById')]
    public function getById(): Person
    {
        return $this;
    }
}
