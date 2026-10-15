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
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\ProjectionDelete;
use Ecotone\Api\Projecting\ProjectionInitialization;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Projecting\Projection;
use Illuminate\Database\ConnectionInterface;

#[Projection('user_list_database')]
#[FromAggregateStream(User::class)]
final class UserListProjection
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    #[ProjectionInitialization]
    public function init(): void
    {
        $this->db->statement('CREATE TABLE IF NOT EXISTS user_list_database (
            user_id VARCHAR(36) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            active BOOLEAN NOT NULL DEFAULT TRUE
        )');
    }

    #[ProjectionDelete]
    public function delete(): void
    {
        $this->db->statement('DROP TABLE IF EXISTS user_list_database');
    }

    #[EventHandler]
    public function onRegistered(UserWasRegistered $event): void
    {
        $this->db->table('user_list_database')->insert([
            'user_id' => $event->userId,
            'name'    => $event->name,
            'email'   => $event->email,
            'active'  => true,
        ]);
    }

    #[EventHandler]
    public function onNameChanged(UserNameWasChanged $event): void
    {
        $this->db->table('user_list_database')
            ->where('user_id', $event->userId)
            ->update(['name' => $event->name]);
    }

    #[EventHandler]
    public function onDeactivated(UserWasDeactivated $event): void
    {
        $this->db->table('user_list_database')
            ->where('user_id', $event->userId)
            ->update(['active' => false]);
    }

    #[QueryHandler('user.listActive')]
    public function listActive(): array
    {
        return $this->db->table('user_list_database')
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();
    }
}
