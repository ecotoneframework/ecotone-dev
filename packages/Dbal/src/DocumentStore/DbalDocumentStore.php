<?php

namespace Ecotone\Dbal\DocumentStore;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\Api\Messaging\ConcurrencyException;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Database\DocumentStoreTableManager;
use Ecotone\Dbal\Database\MissingTableFailure;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Conversion\ConversionException;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Handler\Type\GenericType;
use Ecotone\Messaging\Store\Document\DocumentException;
use Ecotone\Messaging\Store\Document\DocumentNotFound;

use function is_string;
use function json_decode;

use JsonException;
use Throwable;

/**
 * licence Apache-2.0
 */
final class DbalDocumentStore implements DocumentStore
{
    public const ECOTONE_DOCUMENT_STORE = 'ecotone_document_store';

    public function __construct(
        private CachedConnectionFactory $cachedConnectionFactory,
        private ConversionService $conversionService,
        private DocumentStoreTableManager $tableManager,
    ) {
    }

    public function dropCollection(string $collectionName): void
    {
        $this->onTable(fn () => $this->getConnection()->delete(
            $this->getTableName(),
            [
                'collection' => $collectionName,
            ]
        ));
    }

    public function addDocument(string $collectionName, string $documentId, object|array|string $document): void
    {
        try {
            $rowsAffected = $this->insertDocument($collectionName, $documentId, $document);
        } catch (InvalidFieldNameException $missingVersionColumn) {
            throw $this->missingVersionColumnException($missingVersionColumn);
        } catch (TableNotFoundException $missingTable) {
            throw MissingTableFailure::explained($missingTable, $this->tableManager);
        } catch (DriverException $driverException) {
            throw DocumentException::createFromPreviousException(sprintf('Document with id %s can not be added to collection %s. The cause: %s', $documentId, $collectionName, $driverException->getMessage()), $driverException);
        }

        if (1 !== $rowsAffected) {
            throw DocumentNotFound::create(sprintf('There was a problem inserting document with id %s to collection %s. Dbal did not confirm that the record was inserted.', $documentId, $collectionName));
        }
    }

    public function updateDocument(string $collectionName, string $documentId, object|array|string $document, int $expectedVersion): void
    {
        if ($this->updateDocumentInternally($document, $documentId, $collectionName, $expectedVersion) === 1) {
            return;
        }

        $currentVersion = $this->getDocumentVersion($collectionName, $documentId);
        if ($currentVersion === 0) {
            throw DocumentNotFound::create(sprintf('There is no document with id %s in collection %s to update.', $documentId, $collectionName));
        }

        throw ConcurrencyException::forStaleDocument($collectionName, $documentId, $expectedVersion, $currentVersion);
    }

    public function upsertDocument(string $collectionName, string $documentId, object|array|string $document, int $expectedVersion): void
    {
        if ($expectedVersion !== 0 && $this->updateDocumentInternally($document, $documentId, $collectionName, $expectedVersion) === 1) {
            return;
        }

        $currentVersion = $this->getDocumentVersion($collectionName, $documentId);
        if ($expectedVersion !== self::LAST_WRITE_WINS && $expectedVersion !== $currentVersion) {
            throw ConcurrencyException::forStaleDocument($collectionName, $documentId, $expectedVersion, $currentVersion);
        }

        try {
            $this->insertDocument($collectionName, $documentId, $document);
        } catch (UniqueConstraintViolationException) {
            throw ConcurrencyException::forStaleDocument($collectionName, $documentId, $expectedVersion, 1);
        } catch (TableNotFoundException $missingTable) {
            throw MissingTableFailure::explained($missingTable, $this->tableManager);
        } catch (DriverException $driverException) {
            throw DocumentException::createFromPreviousException(sprintf('Document with id %s can not be added to collection %s. The cause: %s', $documentId, $collectionName, $driverException->getMessage()), $driverException);
        }
    }

    public function getDocumentVersion(string $collectionName, string $documentId): int
    {
        try {
            return (int) $this->getConnection()->createQueryBuilder()
                ->select('version')
                ->from($this->getTableName())
                ->andWhere('collection = :collection')
                ->andWhere('document_id = :documentId')
                ->setParameter('collection', $collectionName, Types::TEXT)
                ->setParameter('documentId', $documentId, Types::TEXT)
                ->fetchOne();
        } catch (InvalidFieldNameException $missingVersionColumn) {
            throw $this->missingVersionColumnException($missingVersionColumn);
        } catch (TableNotFoundException $missingTable) {
            throw MissingTableFailure::explained($missingTable, $this->tableManager);
        } catch (DriverException $driverException) {
            if (! $this->getConnection()->createSchemaManager()->introspectTable($this->getTableName())->hasColumn('version')) {
                throw $this->missingVersionColumnException($driverException);
            }

            throw $driverException;
        }
    }

    public function deleteDocument(string $collectionName, string $documentId): void
    {
        $this->onTable(fn () => $this->getConnection()->delete(
            $this->getTableName(),
            [
                'collection' => $collectionName,
                'document_id' => $documentId,
            ]
        ));
    }

    public function getAllDocuments(string $collectionName): array
    {
        $select = $this->onTable(fn () => $this->getDocumentsFor($collectionName)
            ->fetchAllAssociative());

        $documents = [];
        foreach ($select as $documentRecord) {
            $documents[] = $this->convertFromJSONDocument($documentRecord);
        }

        return $documents;
    }

    public function getDocument(string $collectionName, string $documentId): array|object|string
    {
        $document = $this->findDocument($collectionName, $documentId);

        if (is_null($document)) {
            throw DocumentNotFound::create(sprintf('Document with id %s does not exists in Collection %s', $documentId, $collectionName));
        }

        return $document;
    }

    public function findDocument(string $collectionName, string $documentId): array|object|string|null
    {
        $select = $this->onTable(fn () => $this->getDocumentsFor($collectionName)
            ->andWhere('document_id = :documentId')
            ->setParameter('documentId', $documentId, Types::TEXT)
            ->setMaxResults(1)
            ->fetchAllAssociative());

        if (! $select) {
            return null;
        }
        $select = $select[0];

        return $this->convertFromJSONDocument($select);
    }

    public function countDocuments(string $collectionName): int
    {
        $select = $this->onTable(fn () => $this->getConnection()->createQueryBuilder()
            ->select('COUNT(document_id)')
            ->from($this->getTableName())
            ->andWhere('collection = :collection')
            ->setParameter('collection', $collectionName, Types::TEXT)
            ->setMaxResults(1)
            ->fetchFirstColumn());

        if ($select) {
            return $select[0];
        }

        return 0;
    }

    private function getTableName(): string
    {
        return $this->tableManager->getTableName();
    }

    private function onTable(callable $statement): mixed
    {
        try {
            return $statement();
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained($failure, $this->tableManager);
        }
    }

    private function getConnection(): Connection
    {
        /** @var DbalContext $context */
        $context = $this->cachedConnectionFactory->createContext();

        return $context->getDbalConnection();
    }

    private function refuseStringThatIsNotJson(string $collectionName, string $documentId, object|array|string $document): void
    {
        if (! is_string($document)) {
            return;
        }

        try {
            json_decode($document, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $notJson) {
            throw DocumentException::forStringThatIsNotJson($collectionName, $documentId, $notJson);
        }
    }

    private function convertToJSONDocument(Type $type, object|array|string $document): mixed
    {
        if (! $type->isString()) {
            $document = $this->conversionService->convert(
                $document,
                $type,
                MediaType::createApplicationXPHP(),
                Type::string(),
                MediaType::createApplicationJson()
            );
        }
        return $document;
    }

    private function insertDocument(string $collectionName, string $documentId, object|array|string $document): int
    {
        $this->refuseStringThatIsNotJson($collectionName, $documentId, $document);
        $type = Type::createFromVariable($document);

        return $this->getConnection()->insert(
            $this->getTableName(),
            [
                'collection' => $collectionName,
                'document_id' => $documentId,
                'document_type' => $type->toString(),
                'document' => $this->convertToJSONDocument($type, $document),
                'updated_at' => hrtime(true),
                'version' => 1,
            ],
            [
                'collection' => Types::STRING,
                'document_id' => Types::STRING,
                'document_type' => Types::STRING,
                'document' => Types::TEXT,
                'updated_at' => Types::FLOAT,
                'version' => Types::INTEGER,
            ]
        );
    }

    private function updateDocumentInternally(object|array|string $document, string $documentId, string $collectionName, int $expectedVersion): int
    {
        $this->refuseStringThatIsNotJson($collectionName, $documentId, $document);
        $type = Type::createFromVariable($document);
        $update = $this->getConnection()->createQueryBuilder()
            ->update($this->getTableName())
            ->set('document_type', ':documentType')
            ->set('document', ':document')
            ->set('updated_at', ':updatedAt')
            ->set('version', 'version + 1')
            ->where('collection = :collection')
            ->andWhere('document_id = :documentId')
            ->setParameter('documentType', $type->toString(), Types::STRING)
            ->setParameter('document', $this->convertToJSONDocument($type, $document), Types::STRING)
            ->setParameter('updatedAt', hrtime(true), Types::FLOAT)
            ->setParameter('collection', $collectionName, Types::STRING)
            ->setParameter('documentId', $documentId, Types::STRING);

        if ($expectedVersion !== self::LAST_WRITE_WINS) {
            $update
                ->andWhere('version = :expectedVersion')
                ->setParameter('expectedVersion', $expectedVersion, Types::INTEGER);
        }

        try {
            return (int) $update->executeStatement();
        } catch (InvalidFieldNameException $missingVersionColumn) {
            throw $this->missingVersionColumnException($missingVersionColumn);
        } catch (TableNotFoundException $missingTable) {
            throw MissingTableFailure::explained($missingTable, $this->tableManager);
        } catch (DriverException $driverException) {
            throw DocumentException::createFromPreviousException(sprintf('Document with id %s can not be updated in collection %s', $documentId, $collectionName), $driverException);
        }
    }

    private function missingVersionColumnException(DriverException $missingVersionColumn): ConfigurationException
    {
        $tableName = $this->getTableName();

        return ConfigurationException::create(
            "The document store table '{$tableName}' has no 'version' column: it was created by Ecotone 1.x, "
            . 'and since 2.0 every write checks the version of the document it replaces (DocumentStore::updateDocument() and DocumentStore::upsertDocument() take the version the caller read). '
            . "Add the column: ALTER TABLE {$tableName} ADD COLUMN version INTEGER NOT NULL DEFAULT 1; "
            . 'It is safe to run on a populated table: every stored document starts at version 1, which is the version DocumentStore::getDocumentVersion() then reports. '
            . 'Only if the stored documents can be discarded, drop the table instead and let ecotone:migration:database:setup create it again. '
            . "(Driver message: {$missingVersionColumn->getMessage()})"
        );
    }

    private function getDocumentsFor(string $collectionName): QueryBuilder
    {
        return $this->getConnection()->createQueryBuilder()
            ->select('document_id', 'collection', 'document', 'document_type')
            ->from($this->getTableName())
            ->andWhere('collection = :collection')
            ->setParameter('collection', $collectionName, Types::TEXT);
    }

    private function convertFromJSONDocument(mixed $select): mixed
    {
        $documentType = Type::create($select['document_type']);
        if ($documentType->isString()) {
            return $select['document'];
        }

        try {
            $data = $this->conversionService->convert(
                $select['document'],
                Type::string(),
                MediaType::createApplicationJson(),
                $this->typeToReadBackAs($documentType),
                MediaType::createApplicationXPHP()
            );
        } catch (ConversionException $conversionException) {
            throw DocumentException::createFromPreviousException(sprintf(
                "Document with id '%s' in collection '%s' can not be converted from JSON to PHP object of type %s: %s Make the type readable by your converter again, or rewrite or delete the document with DocumentStore::upsertDocument() or DocumentStore::deleteDocument().",
                $select['document_id'],
                $select['collection'],
                $select['document_type'],
                $conversionException->getMessage(),
            ), $conversionException);
        }

        return $data;
    }

    private function typeToReadBackAs(Type $recordedType): Type
    {
        if ($recordedType->isIterable() && $this->namesNoClass($recordedType)) {
            return Type::array();
        }

        return $recordedType;
    }

    private function namesNoClass(Type $type): bool
    {
        if (! $type instanceof GenericType) {
            return ! $type->isClassOrInterface();
        }

        foreach ([$type->type, ...$type->genericTypes] as $genericType) {
            if (! $this->namesNoClass($genericType)) {
                return false;
            }
        }

        return true;
    }
}
