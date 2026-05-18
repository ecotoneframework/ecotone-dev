<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\ReadModel;

use App\Application\ApplyUserDeactivated;
use App\Application\ApplyUserNameChanged;
use App\Application\ApplyUserRegistered;
use App\Domain\Event\UserNameWasChanged;
use App\Domain\Event\UserWasDeactivated;
use App\Domain\Event\UserWasRegistered;
use App\Domain\User;
use App\Models\UserReadModel;
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

    #[EventHandler(outputChannelName: 'user_read_model.apply_registered')]
    public function onRegistered(UserWasRegistered $event): ApplyUserRegistered
    {
        return new ApplyUserRegistered($event->userId, $event->name, $event->email);
    }

    #[EventHandler(outputChannelName: 'user_read_model.apply_name_changed')]
    public function onNameChanged(UserNameWasChanged $event): ApplyUserNameChanged
    {
        return new ApplyUserNameChanged($event->userId, $event->name);
    }

    #[EventHandler(outputChannelName: 'user_read_model.apply_deactivated')]
    public function onDeactivated(UserWasDeactivated $event): ApplyUserDeactivated
    {
        return new ApplyUserDeactivated($event->userId);
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
