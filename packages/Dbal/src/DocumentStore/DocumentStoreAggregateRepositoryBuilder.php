<?php

namespace Ecotone\Dbal\DocumentStore;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Modelling\RepositoryBuilder;

/**
 * licence Apache-2.0
 */
final class DocumentStoreAggregateRepositoryBuilder implements RepositoryBuilder
{
    public function __construct(private string $documentStoreReferenceName, private ?array $relatedAggregates = null)
    {
    }

    public function canHandle(string $aggregateClassName): bool
    {
        return true;
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(DocumentStoreAggregateRepository::class, [
            new Reference($this->documentStoreReferenceName),
            $this->relatedAggregates,
        ]);
    }
}
