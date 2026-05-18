<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\ReadModel;

use App\Domain\Event\UserNameWasChanged;
use App\Domain\Event\UserWasDeactivated;
use App\Domain\Event\UserWasRegistered;
use App\Domain\User;
use App\ReadModel\Command\ChangeUserReadModelName;
use App\ReadModel\Command\DeactivateUserReadModel;
use App\ReadModel\Command\RegisterUserReadModel;
use Ecotone\EventSourcing\Attribute\FromAggregateStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Illuminate\Database\ConnectionInterface;

#[ProjectionV2('user_list_eloquent')]
#[FromAggregateStream(User::class)]
final class UserListProjection
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    #[ProjectionInitialization]
    public function init(): void
    {
        $this->db->statement('CREATE TABLE IF NOT EXISTS user_list_eloquent (
            user_id VARCHAR(36) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            active BOOLEAN NOT NULL DEFAULT TRUE
        )');
    }

    #[ProjectionDelete]
    public function delete(): void
    {
        $this->db->statement('DROP TABLE IF EXISTS user_list_eloquent');
    }

    #[EventHandler(outputChannelName: RegisterUserReadModel::class)]
    public function onRegistered(UserWasRegistered $event): RegisterUserReadModel
    {
        return new RegisterUserReadModel($event->userId, $event->name, $event->email);
    }

    #[EventHandler(outputChannelName: ChangeUserReadModelName::class)]
    public function onNameChanged(UserNameWasChanged $event): ChangeUserReadModelName
    {
        return new ChangeUserReadModelName($event->userId, $event->name);
    }

    #[EventHandler(outputChannelName: DeactivateUserReadModel::class)]
    public function onDeactivated(UserWasDeactivated $event): DeactivateUserReadModel
    {
        return new DeactivateUserReadModel($event->userId);
    }

    #[QueryHandler('user.listActive')]
    public function listActive(): array
    {
        return UserReadModel::where('active', true)
            ->orderBy('name')
            ->get()
            ->toArray();
    }
}
