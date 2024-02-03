<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use Doctrine\ORM\Mapping as ORM;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Identifier;

#[ORM\Entity]
#[ORM\Table(name: 'persons')]
class Person
{
    #[ORM\Id]
    #[ORM\Column(name: 'person_id', type: 'integer')]
    private int $personId;

    #[ORM\Column(name: 'name', type: 'string')]
    private string $name;

    private function __construct(int $personId, string $name)
    {
        $this->personId = $personId;
        $this->name = $name;
    }

    public static function register(RegisterPerson $command): static
    {
        return new self($command->personId, $command->name);
    }

    public function getName(): string
    {
        return $this->name;
    }
}
