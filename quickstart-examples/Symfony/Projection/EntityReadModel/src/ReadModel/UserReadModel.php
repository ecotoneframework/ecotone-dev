<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\ReadModel;

use Doctrine\ORM\Mapping as ORM;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Identifier;

#[Aggregate]
#[ORM\Entity]
#[ORM\Table(name: 'user_list_entity')]
final class UserReadModel
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: 'string', length: 36)]
    #[Identifier]
    private string $userId;

    #[ORM\Column(name: 'name', type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(name: 'email', type: 'string', length: 255)]
    private string $email;

    #[ORM\Column(name: 'active', type: 'boolean')]
    private bool $active;

    private function __construct(string $userId, string $name, string $email, bool $active)
    {
        $this->userId = $userId;
        $this->name = $name;
        $this->email = $email;
        $this->active = $active;
    }

    #[CommandHandler('RegisterUserReadModel')]
    public static function register(array $data): self
    {
        return new self($data['userId'], $data['name'], $data['email'], $data['active']);
    }

    #[CommandHandler('ChangeUserReadModelName')]
    public function changeName(array $data): void
    {
        $this->name = $data['name'];
    }

    #[CommandHandler('DeactivateUserReadModel')]
    public function deactivate(): void
    {
        $this->active = false;
    }
}
