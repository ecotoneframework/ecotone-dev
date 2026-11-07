<?php

namespace Ecotone\Dbal\DbalTransaction;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;
use Ecotone\Api\Dbal\Attribute\DbalTransaction;
use Ecotone\Api\Dbal\ExtensionObject\TransactionReconnectProtection;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\RetryTemplateBuilder;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Messaging\Message;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Dbal\MultiTenant\Pulse\PulseSnapshotRequest;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Recoverability\RetryRunner;
use Ecotone\Modelling\Config\DatabaseTransaction\CommandsWithoutDatabaseTransaction;
use Ecotone\Modelling\Config\DatabaseTransaction\TransactionStatusTracker;
use Exception;
use Throwable;

/**
 * Class DbalTransactionInterceptor
 * @package Ecotone\Amqp\DbalTransaction
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class DbalTransactionInterceptor
{
    /**
     * @param array<string, CachedConnectionFactory> $connectionFactories
     * @param string[] $disableTransactionOnAsynchronousEndpoints
     */
    public function __construct(private array $connectionFactories, private array $disableTransactionOnAsynchronousEndpoints, private RetryRunner $retryRunner, private LoggingGateway $logger, private TransactionStatusTracker $transactionStatusTracker, private CommandsWithoutDatabaseTransaction $commandsWithoutDatabaseTransaction, private TransactionReconnectProtection $transactionReconnectProtection)
    {
    }

    public function transactional(MethodInvocation $methodInvocation, Message $message, ?DbalTransaction $DbalTransaction, ?PollingMetadata $pollingMetadata, ?WithoutDatabaseTransaction $withoutDatabaseTransaction = null)
    {
        if ($withoutDatabaseTransaction !== null || $this->commandsWithoutDatabaseTransaction->isRoutedToHandlerWithoutTransaction($message)) {
            return $methodInvocation->proceed();
        }

        $endpointId = $pollingMetadata?->getEndpointId();

        $connections = [];
        if (! in_array($endpointId, $this->disableTransactionOnAsynchronousEndpoints)) {
            if ($DbalTransaction) {
                $possibleFactories = array_combine($DbalTransaction->connectionReferenceNames, array_map(fn (string $connectionReferenceName) => $this->connectionFactories[$connectionReferenceName], $DbalTransaction->connectionReferenceNames));
            } else {
                $possibleFactories = $this->connectionFactories;
            }

            if (PulseSnapshotRequest::isIn($message)) {
                $possibleFactories = array_filter($possibleFactories, static fn (CachedConnectionFactory $connectionFactory): bool => ! $connectionFactory->getWrappedConnectionFactory() instanceof MultiTenantConnectionFactory || $connectionFactory->getWrappedConnectionFactory()->hasActiveTenant());
            }

            /** @var Connection[] $connections */
            $possibleConnections = array_map(function (CachedConnectionFactory $connectionFactory) {
                /** @var DbalContext $context */
                $context = $connectionFactory->createContext();

                return $context->getDbalConnection();
            }, $possibleFactories);

            foreach ($possibleConnections as $connectionReferenceName => $connection) {
                if ($connection->isTransactionActive()) {
                    continue;
                }

                $connections[$connectionReferenceName] = $connection;
            }
        }

        foreach ($connections as $connectionReferenceName => $connection) {
            $this->refuseConnectionWithoutReconnectProtection($connectionReferenceName, $connection);
        }

        foreach ($connections as $connection) {
            $retryStrategy = RetryTemplateBuilder::exponentialBackOffWithMaxDelay(10, 2, 1000)
                ->maxRetries(2)
                ->build();

            $this->retryRunner->runWithRetry(function () use ($connection) {
                try {
                    $connection->beginTransaction();
                } catch (Exception $exception) {
                    $connection->close();
                    throw $exception;
                }
            }, $retryStrategy, $message, ConnectionException::class, 'Starting Database transaction has failed due to network work, retrying in order to self heal.');
            $this->logger->info('Database Transaction started', $message);
        }
        foreach ($connections as $connection) {
            $this->enterTransactionBoundary($connection);
        }

        $transactionStarted = $connections !== [];
        if ($transactionStarted) {
            $this->transactionStatusTracker->markAsInsideTransaction();
        }

        try {
            $result = $methodInvocation->proceed();

            foreach ($connections as $connection) {
                $connection->commit();
                $this->logger->info('Database Transaction committed', $message);
            }
        } catch (Throwable $exception) {
            foreach ($connections as $connection) {
                try {
                    $this->logger->info(
                        'Exception has been thrown, rolling back transaction.',
                        $message,
                        ['exception' => $exception]
                    );

                    /** Doctrine hold the state, so it needs to be cleaned */
                    $connection->rollBack();
                } catch (Exception) {
                    $connection->close();
                }
            }

            throw $exception;
        } finally {
            foreach ($connections as $connection) {
                self::leaveTransactionBoundary($connection);
            }
            if ($transactionStarted) {
                $this->transactionStatusTracker->markAsOutsideTransaction();
            }
        }

        return $result;
    }

    private function refuseConnectionWithoutReconnectProtection(string $connectionReferenceName, Connection $connection): void
    {
        if ($this->transactionReconnectProtection !== TransactionReconnectProtection::REQUIRED || $connection->getDriver() instanceof TransactionReconnectProtectionDriver) {
            return;
        }

        throw ConfigurationException::create(
            'When the database connection is lost while a message is being handled, the next SQL statement usually fails, and Ecotone rolls the message back and retries it as a whole. '
            . 'In some flows, though — for example a middleware in your codebase, or a library that retries automatically and ignores the connection failure — Doctrine DBAL may reconnect and run the following SQL statements on a new connection, as if nothing had happened. '
            . "That new connection is outside your transaction, so part of the message's data may be stored while the rest is rolled back, and stored again when the message is retried. "
            . 'Ecotone puts stability first, so it prevents this by default: once the connection is lost inside a transaction, it refuses to reconnect until the message ends, and the message is rolled back and retried as a whole. '
            . "The database connection `{$connectionReferenceName}` was built without Ecotone's TransactionReconnectProtectionMiddleware, so Ecotone cannot provide that protection for it. "
            . 'In your setup, add the middleware where the connection is built, as its last middleware: (new Doctrine\\DBAL\\Configuration())->setMiddlewares([..., new Ecotone\\Api\\Dbal\\TransactionReconnectProtectionMiddleware()]). '
            . 'To allow the reconnect instead, turn the protection off for connections built without the middleware with DbalConfiguration::createWithDefaults()->withTransactionReconnectProtection(TransactionReconnectProtection::WHEN_AVAILABLE) (Ecotone\\Api\\Dbal\\ExtensionObject\\TransactionReconnectProtection), or everywhere with ->withTransactionReconnectProtection(TransactionReconnectProtection::IGNORE). '
            . 'Or run the handler without a database transaction: #[WithoutDatabaseTransaction] (Ecotone\\Api\\Attribute\\WithoutDatabaseTransaction), or ->withTransactionOnCommandBus(false), ->withTransactionOnAsynchronousEndpoints(false) or ->withTransactionOnConsoleCommands(false).',
        );
    }

    private function enterTransactionBoundary(Connection $connection): void
    {
        $driver = $connection->getDriver();
        if ($this->transactionReconnectProtection !== TransactionReconnectProtection::IGNORE && $driver instanceof TransactionReconnectProtectionDriver) {
            $driver->enterTransactionBoundary();
        }
    }

    private static function leaveTransactionBoundary(Connection $connection): void
    {
        $driver = $connection->getDriver();
        if ($driver instanceof TransactionReconnectProtectionDriver) {
            $driver->leaveTransactionBoundary();
        }
    }
}
