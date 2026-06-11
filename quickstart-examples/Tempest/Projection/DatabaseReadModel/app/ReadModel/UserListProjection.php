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
use Doctrine\DBAL\ParameterType;
use Ecotone\EventSourcing\Attribute\FromAggregateStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Interop\Queue\ConnectionFactory;

#[ProjectionV2('user_list_database')]
#[FromAggregateStream(User::class)]
final class UserListProjection
{
    public function __construct(private ConnectionFactory $connectionFactory)
    {
    }

    #[ProjectionInitialization]
    public function init(): void
    {
        $this->getConnection()->executeStatement('CREATE TABLE IF NOT EXISTS user_list_database (
            user_id VARCHAR(36) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            active BOOLEAN NOT NULL DEFAULT TRUE
        )');
    }

    #[ProjectionDelete]
    public function delete(): void
    {
        $this->getConnection()->executeStatement('DROP TABLE IF EXISTS user_list_database');
    }

    #[EventHandler]
    public function onRegistered(UserWasRegistered $event): void
    {
        $this->getConnection()->insert('user_list_database', [
            'user_id' => $event->userId,
            'name'    => $event->name,
            'email'   => $event->email,
            'active'  => true,
        ], ['active' => ParameterType::BOOLEAN]);
    }

    #[EventHandler]
    public function onNameChanged(UserNameWasChanged $event): void
    {
        $this->getConnection()->update(
            'user_list_database',
            ['name' => $event->name],
            ['user_id' => $event->userId],
        );
    }

    #[EventHandler]
    public function onDeactivated(UserWasDeactivated $event): void
    {
        $this->getConnection()->update(
            'user_list_database',
            ['active' => false],
            ['user_id' => $event->userId],
            ['active' => ParameterType::BOOLEAN],
        );
    }

    #[QueryHandler('user.listActive')]
    public function listActive(): array
    {
        return $this->getConnection()
            ->executeQuery('SELECT user_id, name, email, active FROM user_list_database WHERE active = true ORDER BY name')
            ->fetchAllAssociative();
    }

    private function getConnection(): Connection
    {
        return $this->connectionFactory->createContext()->getDbalConnection();
    }
}
