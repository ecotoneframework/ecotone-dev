<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Consumer;

use Ecotone\Messaging\Consumer\ConsumerPositionTracker;
use Ecotone\Messaging\Store\Document\DocumentStore;

/**
 * DBAL-based position tracker for persistent consumer offset storage using DocumentStore
 * licence Apache-2.0
 */
class DbalConsumerPositionTracker implements ConsumerPositionTracker
{
    private const COLLECTION_NAME = 'ecotone_consumer_positions';

    public function __construct(
        private DocumentStore $documentStore
    ) {
    }

    public function loadPosition(string $consumerId): ?string
    {
        return $this->documentStore->findDocument(self::COLLECTION_NAME, $consumerId);
    }

    public function savePosition(string $consumerId, string $position): void
    {
        $this->documentStore->upsertDocument(self::COLLECTION_NAME, $consumerId, $position);
    }

    public function deletePosition(string $consumerId): void
    {
        $this->documentStore->deleteDocument(self::COLLECTION_NAME, $consumerId);
    }
}
