<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;

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

    public function getConnection(): Connection
    {
        return $this->registry->getConnection($this->config['connection_name'] ?? null);
    }
}
