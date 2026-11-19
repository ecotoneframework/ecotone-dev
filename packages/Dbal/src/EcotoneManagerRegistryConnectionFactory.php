<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Ecotone\Api\Dbal\ManagerRegistryConnectionFactory;

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

    public function getManager(): ObjectManager
    {
        $connection = $this->getConnection();
        foreach (array_keys($this->registry->getManagerNames()) as $managerName) {
            $manager = $this->registry->getManager(is_string($managerName) ? $managerName : null);
            if ($manager instanceof EntityManagerInterface && $manager->getConnection() === $connection) {
                return $manager;
            }
        }

        return $this->registry->getManager();
    }

    public function getConnection(): Connection
    {
        return $this->registry->getConnection($this->config['connection_name'] ?? null);
    }
}
