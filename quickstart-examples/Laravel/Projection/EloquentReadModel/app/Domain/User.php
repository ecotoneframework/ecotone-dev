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
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
final class User
{
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

    #[EventSourcingHandler]
    public function applyRegistered(UserWasRegistered $event): void
    {
        $this->userId = $event->userId;
        $this->name = $event->name;
        $this->active = true;
    }

    #[EventSourcingHandler]
    public function applyNameChanged(UserNameWasChanged $event): void
    {
        $this->name = $event->name;
    }

    #[EventSourcingHandler]
    public function applyDeactivated(UserWasDeactivated $event): void
    {
        $this->active = false;
    }
}
