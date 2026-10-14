<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Command\ChangeUserName;
use App\Domain\Command\DeactivateUser;
use App\Domain\Command\RegisterUser;
use App\Domain\Event\UserNameWasChanged;
use App\Domain\Event\UserWasDeactivated;
use App\Domain\Event\UserWasRegistered;
use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
#[Stream(self::STREAM)]
final class User
{
    public const STREAM = 'symfony_entity_read_model_users';

    use WithAggregateVersioning;

    #[Identifier]
    private string $userId;

    private string $name;

    private bool $active;

    #[CommandHandler]
    public static function register(RegisterUser $command): array
    {
        return [new UserWasRegistered($command->userId, $command->name, $command->email)];
    }

    #[CommandHandler]
    public function changeName(ChangeUserName $command): array
    {
        if ($command->name === $this->name) {
            return [];
        }

        return [new UserNameWasChanged($this->userId, $command->name)];
    }

    #[CommandHandler]
    public function deactivate(DeactivateUser $command): array
    {
        if (! $this->active) {
            return [];
        }

        return [new UserWasDeactivated($this->userId)];
    }

    #[Apply]
    public function applyRegistered(UserWasRegistered $event): void
    {
        $this->userId = $event->userId;
        $this->name = $event->name;
        $this->active = true;
    }

    #[Apply]
    public function applyNameChanged(UserNameWasChanged $event): void
    {
        $this->name = $event->name;
    }

    #[Apply]
    public function applyDeactivated(UserWasDeactivated $event): void
    {
        $this->active = false;
    }
}
