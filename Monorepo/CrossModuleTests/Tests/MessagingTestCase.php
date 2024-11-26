<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\Deduplication\DeduplicationInterceptor;
use Ecotone\Dbal\DocumentStore\DbalDocumentStore;
use Ecotone\Dbal\Recoverability\DbalDeadLetterHandler;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

final class MessagingTestCase
{
    public static function cleanUpDbal(): void
    {
        $connection = DbalMessagingTestCase::prepareConnection()->createContext()->getDbalConnection();

        self::deleteDatabaseTable('enqueue', $connection);
        self::deleteDatabaseTable(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE, $connection);
        self::deleteDatabaseTable(DbalDocumentStore::ECOTONE_DOCUMENT_STORE, $connection);
        self::deleteDatabaseTable(DeduplicationInterceptor::DEFAULT_DEDUPLICATION_TABLE, $connection);
    }

    public static function cleanRabbitMQ(): void
    {
        self::cleanUpDbal();
        $context = AmqpMessagingTestCase::getRabbitConnectionFactory()->createContext();

        foreach (['async'] as $queue) {
            try {
                $context->deleteQueue($context->createQueue($queue));
            }catch (\Exception $e) {}
        }
    }

    public static function cleanUpSqs(): void
    {
        self::cleanUpDbal();
        $context = \Test\Ecotone\Sqs\ConnectionTestCase::getConnection()->createContext();

        foreach (['async'] as $queue) {
            try {
                $context->deleteQueue($context->createQueue($queue));
            }catch (\Exception $e) {}
        }
    }

    public static function cleanUpRedis(): void
    {
        self::cleanUpDbal();
        $context = \Test\Ecotone\Redis\ConnectionTestCase::getConnection()->createContext();

        foreach (['async'] as $queue) {
            try {
                $context->deleteQueue($context->createQueue($queue));
            }catch (\Exception $e) {}
        }
    }

    private static function deleteDatabaseTable(string $tableName, Connection $connection): void
    {
        $schemaManager = method_exists($connection, 'getSchemaManager') ? $connection->getSchemaManager() : $connection->createSchemaManager();

        if ($schemaManager->tablesExist([$tableName])) {
            $connection->executeStatement('DROP TABLE ' . $tableName);
        }
    }
}