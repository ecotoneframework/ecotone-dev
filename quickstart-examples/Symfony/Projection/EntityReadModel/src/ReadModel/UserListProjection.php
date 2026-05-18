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
use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\Attribute\FromAggregateStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute\ProjectionV2;

#[ProjectionV2('user_list_entity')]
#[FromAggregateStream(User::class)]
final class UserListProjection
{
    public function __construct(private Connection $connection)
    {
    }

    #[ProjectionInitialization]
    public function init(): void
    {
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS user_list_entity (
            user_id VARCHAR(36) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            active BOOLEAN NOT NULL DEFAULT TRUE
        )');
    }

    #[ProjectionDelete]
    public function delete(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS user_list_entity');
    }

    #[EventHandler(outputChannelName: 'RegisterUserReadModel')]
    public function onRegistered(UserWasRegistered $event): array
    {
        return [
            'userId' => $event->userId,
            'name' => $event->name,
            'email' => $event->email,
            'active' => true,
        ];
    }

    #[EventHandler(outputChannelName: 'ChangeUserReadModelName')]
    public function onNameChanged(UserNameWasChanged $event): array
    {
        return [
            'userId' => $event->userId,
            'name' => $event->name,
        ];
    }

    #[EventHandler(outputChannelName: 'DeactivateUserReadModel')]
    public function onDeactivated(UserWasDeactivated $event): array
    {
        return [
            'userId' => $event->userId,
        ];
    }

    #[QueryHandler('user.listActive')]
    public function listActive(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT user_id, name, email, active FROM user_list_entity WHERE active = TRUE ORDER BY name ASC',
        );

        return array_map(fn (array $row) => [
            'user_id' => $row['user_id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'active' => $row['active'],
        ], $rows);
    }
}
