<?php

namespace Ecotone\Dbal\DocumentStore;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
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

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(DocumentStoreAggregateRepository::class, [
            new Reference($this->documentStoreReferenceName),
        ]);
    }
}
