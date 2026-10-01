<?php

namespace Ecotone\Dbal\DocumentStore;

use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\Messaging\Support\ConcurrencyException;
use Ecotone\Modelling\StateStoredRepository;

/**
 * licence Apache-2.0
 */
final class DocumentStoreAggregateRepository implements StateStoredRepository
{
    private const COLLECTION_NAME = 'aggregates_';

    public function __construct(private DocumentStore $documentStore, private ?array $relatedAggregates = null)
    {
    }

    public function canHandle(string $aggregateClassName): bool
    {
        if (is_null($this->relatedAggregates)) {
            return false;
        }

        return in_array($aggregateClassName, $this->relatedAggregates);
    }

    public function findBy(string $aggregateClassName, array $identifiers): ?object
    {
        $aggregateId = array_pop($identifiers);

        return $this->documentStore->findDocument($this->getCollectionName($aggregateClassName), $aggregateId);
    }

    public function save(array $identifiers, object $aggregate, array $metadata, ?int $versionBeforeHandling): void
    {
        $aggregateId = array_pop($identifiers);
        $collectionName = $this->getCollectionName($aggregate::class);
        $expectedVersion = $versionBeforeHandling ?? DocumentStore::LAST_WRITE_WINS;

        try {
            $this->documentStore->upsertDocument($collectionName, $aggregateId, $aggregate, $expectedVersion);
        } catch (ConcurrencyException) {
            throw ConcurrencyException::forStaleAggregate($aggregate::class, (string) $aggregateId, $expectedVersion, $this->documentStore->getDocumentVersion($collectionName, $aggregateId));
        }
    }

    private function getCollectionName(string $aggregateClassName): string
    {
        return self::COLLECTION_NAME . $aggregateClassName;
    }
}
