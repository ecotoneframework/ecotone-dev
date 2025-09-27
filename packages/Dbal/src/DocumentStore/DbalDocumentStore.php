<?php

namespace Ecotone\Dbal\DocumentStore;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Ecotone\Dbal\Compatibility\QueryBuilderProxy;
use Ecotone\Dbal\Compatibility\SchemaManagerCompatibility;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Conversion\ConversionException;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Store\Document\DocumentException;
use Ecotone\Messaging\Store\Document\DocumentNotFound;
use Ecotone\Messaging\Store\Document\DocumentStore;
use Enqueue\Dbal\DbalContext;

use function spl_object_id;

/**
 * licence Apache-2.0
 */
final class DbalDocumentStore implements DocumentStore
{
    public const ECOTONE_DOCUMENT_STORE = 'ecotone_document_store';

    public function __construct(
        private CachedConnectionFactory $cachedConnectionFactory,
        private bool $autoDeclare,
        private ConversionService $conversionService,
        private array $initialized = [],
    ) {
    }

    public function dropCollection(string $collectionName): void
    {
        if (! $this->doesTableExists()) {
            return;
        }

        $this->getConnection()->delete(
            $this->getTableName(),
            [
                'collection' => $collectionName,
            ]
        );
    }

    public function addDocument(string $collectionName, string $documentId, object|array|string $document): void
    {
        $this->createDataBaseTable();

        try {
            $type = Type::createFromVariable($document);

            $rowsAffected = $this->getConnection()->insert(
                $this->getTableName(),
                [
                    'collection' => $collectionName,
                    'document_id' => $documentId,
                    'document_type' => $type->toString(),
                    'document' => $this->convertToJSONDocument($type, $document),
                    'updated_at' => hrtime(true),
                ],
                [
                    'collection' => Types::STRING,
                    'document_id' => Types::STRING,
                    'document_type' => Types::STRING,
                    'document' => Types::TEXT,
                    'updated_at' => Types::FLOAT,
                ]
            );
        } catch (DriverException $driverException) {
            throw DocumentException::createFromPreviousException(sprintf('Document with id %s can not be added to collection %s. The cause: %s', $documentId, $collectionName, $driverException->getMessage()), $driverException);
        }

        if (1 !== $rowsAffected) {
            throw DocumentNotFound::create(sprintf('There was a problem inserting document with id %s to collection %s. Dbal did not confirm that the record was inserted.', $documentId, $collectionName));
        }
    }

    public function updateDocument(string $collectionName, string $documentId, object|array|string $document): void
    {
        $this->createDataBaseTable();

        $rowsAffected = $this->updateDocumentInternally($document, $documentId, $collectionName);

        if (1 !== $rowsAffected) {
            throw DocumentNotFound::create(sprintf('There is no document with id %s in collection %s to update.', $documentId, $collectionName));
        }
    }

    public function upsertDocument(string $collectionName, string $documentId, object|array|string $document): void
    {
        $this->createDataBaseTable();

        $rowsAffected = $this->updateDocumentInternally($document, $documentId, $collectionName);

        if ($rowsAffected === 0) {
            $this->addDocument($collectionName, $documentId, $document);
        }
    }

    public function deleteDocument(string $collectionName, string $documentId): void
    {
        if (! $this->doesTableExists()) {
            return;
        }

        $this->getConnection()->delete(
            $this->getTableName(),
            [
                'collection' => $collectionName,
                'document_id' => $documentId,
            ]
        );
    }

    public function getAllDocuments(string $collectionName): array
    {
        if (! $this->doesTableExists()) {
            return [];
        }

        $select = $this->getDocumentsFor($collectionName)
            ->fetchAllAssociative();

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
        if (! $this->doesTableExists()) {
            return null;
        }

        $select = $this->getDocumentsFor($collectionName)
            ->andWhere('document_id = :documentId')
            ->setParameter('documentId', $documentId, Types::TEXT)
            ->setMaxResults(1)
            ->fetchAllAssociative();

        if (! $select) {
            return null;
        }
        $select = $select[0];

        return $this->convertFromJSONDocument($select);
    }

    public function countDocuments(string $collectionName): int
    {
        if (! $this->doesTableExists()) {
            return 0;
        }

        $select = (new QueryBuilderProxy($this->getConnection()->createQueryBuilder()))
            ->select('COUNT(document_id)')
            ->from($this->getTableName())
            ->andWhere('collection = :collection')
            ->setParameter('collection', $collectionName, Types::TEXT)
            ->setMaxResults(1)
            ->fetchFirstColumn();

        if ($select) {
            return $select[0];
        }

        return 0;
    }

    private function getTableName(): string
    {
        return self::ECOTONE_DOCUMENT_STORE;
    }

    private function createDataBaseTable(): void
    {
        if (! $this->autoDeclare) {
            return;
        }

        if ($this->doesTableExists()) {
            return;
        }

        $schemaManager = SchemaManagerCompatibility::getSchemaManager($this->getConnection());

        $table = new Table($this->getTableName());

        $table->addColumn('collection', 'string', ['length' => 255]);
        $table->addColumn('document_id', 'string', ['length' => 255]);
        $table->addColumn('document_type', 'text');
        $table->addColumn('document', 'json');
        $table->addColumn('updated_at', 'float', ['length' => 53]);

        $table->setPrimaryKey(['collection', 'document_id']);

        $schemaManager->createTable($table);
    }

    private function getConnection(): Connection
    {
        /** @var DbalContext $context */
        $context = $this->cachedConnectionFactory->createContext();

        return $context->getDbalConnection();
    }

    private function doesTableExists(): bool
    {
        if (! $this->autoDeclare) {
            return true;
        }
        $connection = $this->getConnection();

        if (isset($this->initialized[spl_object_id($connection)])) {
            return true;
        }

        $schemaManager = $connection->createSchemaManager();
        $tableExists = $schemaManager->tablesExist([$this->getTableName()]);

        if ($tableExists) {
            $this->initialized[spl_object_id($connection)] = true;
        }

        return $tableExists;
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

    private function updateDocumentInternally(object|array|string $document, string $documentId, string $collectionName): int
    {
        try {
            $type = Type::createFromVariable($document);

            $rowsAffected = $this->getConnection()->update(
                $this->getTableName(),
                [
                    'document_type' => $type->toString(),
                    'document' => $this->convertToJSONDocument($type, $document),
                    'updated_at' => hrtime(true),
                ],
                [
                    'document_id' => $documentId,
                    'collection' => $collectionName,
                ],
                [
                    'collection' => Types::STRING,
                    'document_id' => Types::STRING,
                    'document_type' => Types::STRING,
                    'document' => Types::STRING,
                    'updated_at' => Types::FLOAT,
                ]
            );
        } catch (DriverException $driverException) {
            throw DocumentException::createFromPreviousException(sprintf('Document with id %s can not be updated in collection %s', $documentId, $collectionName), $driverException);
        }

        return $rowsAffected;
    }

    private function getDocumentsFor(string $collectionName): mixed
    {
        return (new QueryBuilderProxy($this->getConnection()->createQueryBuilder()))
            ->select('document', 'document_type')
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
                $documentType,
                MediaType::createApplicationXPHP()
            );
        } catch (ConversionException $conversionException) {
            throw DocumentException::createFromPreviousException(sprintf('Document with id %s can not be converted from JSON to PHP object', $select['document_id']), $conversionException);
        }

        return $data;
    }
}
