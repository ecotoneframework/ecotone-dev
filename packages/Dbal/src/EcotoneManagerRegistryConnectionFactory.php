<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Ecotone\Api\Dbal\ManagerRegistryConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Apache-2.0
 */
final class EcotoneManagerRegistryConnectionFactory extends ManagerRegistryConnectionFactory
{
    private ManagerRegistry $registry;
    /** @var array<string, string> */
    private array $config;

    public function __construct(ManagerRegistry $registry, array $config = [])
    {
        parent::__construct($registry, $config);

        $this->registry = $registry;
        $this->config = $config;
    }

    public function getRegistry(): ManagerRegistry
    {
        return $this->registry;
    }

    public function getManagerOfConnectionRegisteredAs(string $connectionReferenceName): ObjectManager
    {
        $connection = $this->getConnection();
        foreach (array_keys($this->registry->getManagerNames()) as $managerName) {
            $manager = $this->registry->getManager(is_string($managerName) ? $managerName : null);
            if ($manager instanceof EntityManagerInterface && $manager->getConnection() === $connection) {
                return $manager;
            }
        }

        $connectionName = $this->config['connection_name'] ?? $this->registry->getDefaultConnectionName();
        throw ConfigurationException::create(sprintf(
            '#[CurrentConnectionObjectManager] cannot inject the Doctrine ObjectManager of connection `%s` (Doctrine connection `%s`): none of the entity managers of its ManagerRegistry (`%s`) uses that connection. '
            . 'Configure an entity manager on the connection (in Symfony: an entry of doctrine.orm.entity_managers with `connection: %s`), '
            . 'or take #[CurrentConnection] %s to run SQL on it without Doctrine ORM.',
            $connectionReferenceName,
            $connectionName,
            implode('`, `', array_keys($this->registry->getManagerNames())),
            $connectionName,
            Connection::class,
        ));
    }

    public function getConnection(): Connection
    {
        return $this->registry->getConnection($this->config['connection_name'] ?? null);
    }
}
