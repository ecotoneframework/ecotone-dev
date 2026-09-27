<?php

namespace Ecotone\Dbal\DbalTransaction;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Connection\ManagerRegistryConnectionFactory;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Handler\Recoverability\RetryRunner;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\Message;
use Ecotone\Modelling\Config\DatabaseTransaction\TransactionStatusTracker;
use Ecotone\Modelling\Config\MessageBusChannel;
use Exception;
use Interop\Queue\ConnectionFactory;
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
     * @param array<string, DbalConnectionFactory|ManagerRegistryConnectionFactory> $connectionFactories
     * @param string[] $disableTransactionOnAsynchronousEndpoints
     * @param string[] $commandRoutingKeysWithoutTransaction
     */
    public function __construct(private array $connectionFactories, private array $disableTransactionOnAsynchronousEndpoints, private RetryRunner $retryRunner, private LoggingGateway $logger, private TransactionStatusTracker $transactionStatusTracker, private array $commandRoutingKeysWithoutTransaction = [])
    {
    }

    public function transactional(MethodInvocation $methodInvocation, Message $message, ?DbalTransaction $DbalTransaction, ?PollingMetadata $pollingMetadata, ?WithoutDatabaseTransaction $withoutDatabaseTransaction = null)
    {
        if ($withoutDatabaseTransaction !== null || $this->isRoutedToHandlerWithoutTransaction($message)) {
            return $methodInvocation->proceed();
        }

        $endpointId = $pollingMetadata?->getEndpointId();

        $connections = [];
        if (! in_array($endpointId, $this->disableTransactionOnAsynchronousEndpoints)) {
            if ($DbalTransaction) {
                $possibleFactories = array_map(fn (string $connectionReferenceName) => $this->connectionFactories[$connectionReferenceName], $DbalTransaction->connectionReferenceNames);
            } else {
                $possibleFactories = $this->connectionFactories;
            }

            /** @var Connection[] $connections */
            $possibleConnections = array_map(function (ConnectionFactory $connectionFactory) {
                $connectionFactory = CachedConnectionFactory::createFor(new DbalReconnectableConnectionFactory($connectionFactory));

                /** @var DbalContext $context */
                $context = $connectionFactory->createContext();

                return $context->getDbalConnection();
            }, $possibleFactories);

            foreach ($possibleConnections as $connection) {
                if ($connection->isTransactionActive()) {
                    continue;
                }

                $connections[] = $connection;
            }
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
            if ($transactionStarted) {
                $this->transactionStatusTracker->markAsOutsideTransaction();
            }
        }

        return $result;
    }

    private function isRoutedToHandlerWithoutTransaction(Message $message): bool
    {
        if ($this->commandRoutingKeysWithoutTransaction === []) {
            return false;
        }

        if (! $message->getHeaders()->containsKey(MessageBusChannel::COMMAND_CHANNEL_NAME_BY_NAME)) {
            return false;
        }

        return in_array($message->getHeaders()->get(MessageBusChannel::COMMAND_CHANNEL_NAME_BY_NAME), $this->commandRoutingKeysWithoutTransaction, true);
    }
}
