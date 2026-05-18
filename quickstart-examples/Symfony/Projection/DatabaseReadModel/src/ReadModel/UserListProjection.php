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

#[ProjectionV2('user_list_database')]
#[FromAggregateStream(User::class)]
final class UserListProjection
{
    public function __construct(private Connection $connection)
    {
    }

    #[ProjectionInitialization]
    public function init(): void
    {
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS user_list_database (
            user_id VARCHAR(36) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            active BOOLEAN NOT NULL DEFAULT TRUE
        )');
    }

    #[ProjectionDelete]
    public function delete(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS user_list_database');
    }

    #[EventHandler]
    public function onRegistered(UserWasRegistered $event): void
    {
        $this->connection->executeStatement(
            'INSERT INTO user_list_database (user_id, name, email, active) VALUES (:user_id, :name, :email, TRUE)',
            ['user_id' => $event->userId, 'name' => $event->name, 'email' => $event->email],
        );
    }

    #[EventHandler]
    public function onNameChanged(UserNameWasChanged $event): void
    {
        $this->connection->executeStatement(
            'UPDATE user_list_database SET name = :name WHERE user_id = :user_id',
            ['name' => $event->name, 'user_id' => $event->userId],
        );
    }

    #[EventHandler]
    public function onDeactivated(UserWasDeactivated $event): void
    {
        $this->connection->executeStatement(
            'UPDATE user_list_database SET active = FALSE WHERE user_id = :user_id',
            ['user_id' => $event->userId],
        );
    }

    #[QueryHandler('user.listActive')]
    public function listActive(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT user_id, name, email, active FROM user_list_database WHERE active = TRUE ORDER BY name ASC',
        );
    }
}
