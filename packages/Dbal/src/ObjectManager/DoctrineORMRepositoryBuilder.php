<?php

declare(strict_types=1);

namespace Ecotone\Dbal\ObjectManager;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Modelling\RepositoryBuilder;

/**
 * licence Apache-2.0
 */
class DoctrineORMRepositoryBuilder implements RepositoryBuilder
{
    public function __construct(private DbalConfiguration $dbalConfiguration)
    {
    }

    public function canHandle(string $aggregateClassName): bool
    {
        if (is_null($this->dbalConfiguration->getDoctrineORMClasses())) {
            return false;
        }

        return in_array($aggregateClassName, $this->dbalConfiguration->getDoctrineORMClasses());
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(ManagerRegistryRepository::class, [
            new Reference($this->dbalConfiguration->getDoctrineORMRepositoryConnectionReference()),
            $this->dbalConfiguration->getDoctrineORMClasses(),
            $this->dbalConfiguration->isFlushingDuringPersisting(),
        ]);
    }
}
