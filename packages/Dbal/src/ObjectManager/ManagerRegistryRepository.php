<?php

declare(strict_types=1);

namespace Ecotone\Dbal\ObjectManager;

use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\StandardRepository;
use Interop\Queue\ConnectionFactory;

class ManagerRegistryRepository implements StandardRepository
{
    public function __construct(private ConnectionFactory $connectionFactory, private ?array $relatedClasses)
    {
    }

    public function canHandle(string $aggregateClassName): bool
    {
        if (is_null($this->relatedClasses)) {
            return true;
        }

        return in_array($aggregateClassName, $this->relatedClasses);
    }

    public function findBy(string $aggregateClassName, array $identifiers): ?object
    {
        return $this->getManagerRegistry()->getRepository($aggregateClassName)->findOneBy($identifiers);
    }

    public function save(array $identifiers, object $aggregate, array $metadata, ?int $versionBeforeHandling): void
    {
        $objectManager = $this->getManagerRegistry()->getManagerForClass(get_class($aggregate));

        $objectManager->persist($aggregate);
    }

    private function getManagerRegistry(): ManagerRegistry
    {
        $connectionFactory = $this->connectionFactory;
        if ($connectionFactory instanceof MultiTenantConnectionFactory) {
            $connectionFactory = $connectionFactory->getConnectionFactory();
        }

        if ($connectionFactory instanceof EcotoneManagerRegistryConnectionFactory) {
            return $connectionFactory->getRegistry();
        }

        throw new InvalidArgumentException('To make use of Doctrine ORM based Aggregates, you need to construct your Connection using DbalConnection::createForManagerRegistry() method (https://docs.ecotone.tech/modules/dbal-support).');
    }
}
