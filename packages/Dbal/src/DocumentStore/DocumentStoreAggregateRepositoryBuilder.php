<?php

namespace Ecotone\Dbal\DocumentStore;

use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Modelling\RepositoryBuilder;

final class DocumentStoreAggregateRepositoryBuilder implements RepositoryBuilder
{
    public function __construct(private string $documentStoreReferenceName)
    {
    }

    public function canHandle(string $aggregateClassName): bool
    {
        return true;
    }

    public function isEventSourced(): bool
    {
        return false;
    }

    public function compile(ContainerMessagingBuilder $builder): Definition
    {
        return new Definition(DocumentStoreAggregateRepository::class, [
            new Reference($this->documentStoreReferenceName),
        ]);
    }
}
