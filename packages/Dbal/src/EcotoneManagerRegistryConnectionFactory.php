<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Doctrine\Persistence\ManagerRegistry;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;

final class EcotoneManagerRegistryConnectionFactory extends ManagerRegistryConnectionFactory
{
    private ManagerRegistry $registry;

    public function __construct(ManagerRegistry $registry, array $config = [])
    {
        parent::__construct($registry, $config);

        $this->registry = $registry;
    }

    public function getRegistry(): ManagerRegistry
    {
        return $this->registry;
    }
}
