<?php

declare(strict_types=1);

namespace Ecotone\Dbal\ObjectManager;

use Doctrine\Persistence\ObjectManager;
use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\StandardRepository;
use Interop\Queue\ConnectionFactory;

/**
 * licence Apache-2.0
 */
class ManagerRegistryRepository implements StandardRepository
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
        private ?array $relatedClasses,
        private bool $autoFlushOnCommand
    ) {
    }

    public function canHandle(string $aggregateClassName): bool
    {
        if (is_null($this->relatedClasses)) {
            return $this->getManagerRegistry($aggregateClassName) !== null;
        }

        return in_array($aggregateClassName, $this->relatedClasses);
    }

    public function findBy(string $aggregateClassName, array $identifiers): ?object
    {
        return $this->getManagerRegistry($aggregateClassName)->find($aggregateClassName, $identifiers);
    }

    public function save(array $identifiers, object $aggregate, array $metadata, ?int $versionBeforeHandling): void
    {
        $objectManager = $this->getManagerRegistry(get_class($aggregate));

        $objectManager->persist($aggregate);
        if ($this->autoFlushOnCommand) {
            $objectManager->flush();
        }
    }

    private function getManagerRegistry(string $aggregateClass): ?ObjectManager
    {
        $connectionFactory = $this->connectionFactory;
        if ($connectionFactory instanceof MultiTenantConnectionFactory) {
            return $connectionFactory->getManager();
        }

        if ($connectionFactory instanceof EcotoneManagerRegistryConnectionFactory) {
            return $connectionFactory->getRegistry()->getManagerForClass($aggregateClass);
        }

        throw new InvalidArgumentException('To make use of Doctrine ORM based Aggregates, you need to construct your Connection using DbalConnection::createForManagerRegistry() method (https://docs.ecotone.tech/modules/dbal-support).');
    }
}
