<?php

namespace Ecotone\Dbal\DbalTransaction;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Handler\Recoverability\RetryRunner;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\Message;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\DbalContext;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;
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
     */
    public function __construct(private array $connectionFactories, private array $disableTransactionOnAsynchronousEndpoints, private RetryRunner $retryRunner, private LoggingGateway $logger)
    {
    }

    public function transactional(MethodInvocation $methodInvocation, Message $message, ?DbalTransaction $DbalTransaction, ?PollingMetadata $pollingMetadata)
    {
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
            $retryStrategy = RetryTemplateBuilder::exponentialBackoffWithMaxDelay(10, 2, 1000)
                ->maxRetryAttempts(2)
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
        try {
            $result = $methodInvocation->proceed();

            foreach ($connections as $connection) {
                try {
                    $connection->commit();
                    $this->logger->info('Database Transaction committed', $message);
                } catch (Exception $exception) {
                    // Handle the case where a database did an implicit commit or the transaction is no longer active
                    /** @TODO Ecotone 2.0 remove implicit commit and tables creation on fly, and provide CLI command instead */
                    if (ImplicitCommit::isImplicitCommitException($exception, $connection)) {
                        $this->logger->info(
                            sprintf('Implicit Commit was detected, skipping manual one.'),
                            $message,
                            ['exception' => $exception],
                        );

                        try {
                            $connection->rollBack();
                        } catch (Exception) {
                            // Ignore rollback errors after implicit commit
                        };

                        continue;
                    }

                    throw $exception;
                }
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
        }

        return $result;
    }
}
