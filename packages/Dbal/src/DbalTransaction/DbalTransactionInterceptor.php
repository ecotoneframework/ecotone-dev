<?php

namespace Ecotone\Dbal\DbalTransaction;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Logger\LoggingHandlerBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\DbalContext;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;
use Exception;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;
use Ecotone\Messaging\Message;

/**
 * Class DbalTransactionInterceptor
 * @package Ecotone\Amqp\DbalTransaction
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class DbalTransactionInterceptor
{
    /**
     * @param array<string, DbalConnectionFactory|ManagerRegistryConnectionFactory> $connectionFactories
     * @param string[] $disableTransactionOnAsynchronousEndpoints
     */
    public function __construct(private array $connectionFactories, private array $disableTransactionOnAsynchronousEndpoints)
    {
    }

    public function transactional(MethodInvocation $methodInvocation, Message $message, ?DbalTransaction $DbalTransaction, ?PollingMetadata $pollingMetadata, #[Reference(LoggingGateway::class)] LoggingGateway $logger)
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
            $possibleConnections = array_map(function (DbalConnectionFactory|ManagerRegistryConnectionFactory $connection) {
                $connectionFactory = CachedConnectionFactory::createFor(new DbalReconnectableConnectionFactory($connection));

                /** @var DbalContext $context */
                $context = $connectionFactory->createContext();

                return  $context->getDbalConnection();
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

            $retryStrategy->runCallbackWithRetries(function () use ($connection) {
                $connection->beginTransaction();
            }, $message, ConnectionException::class, $logger, 'Starting Database transaction has failed due to network work, retrying in order to self heal.');
            $logger->info('Database Transaction started', $message);
        }
        try {
            $result = $methodInvocation->proceed();

            foreach ($connections as $connection) {
                try {
                    $connection->commit();
                    $logger->info('Database Transaction committed', $message);
                } catch (PDOException $exception) {
                    /** Handles the case where Mysql did implicit commit, when new creating tables */
                    if (! str_contains($exception->getMessage(), 'There is no active transaction')) {
                        $logger->info(
                            'Failure on committing transaction.',
                            $message,
                            $exception
                        );

                        throw $exception;
                    }

                    $logger->info(
                        'Implicit Commit was detected, skipping manual one.',
                        $message,
                        $exception
                    );
                    /** Doctrine hold the state, so it needs to be cleaned */
                    try {
                        $connection->rollBack();
                    } catch (Exception) {
                    };
                }
            }
        } catch (Throwable $exception) {
            foreach ($connections as $connection) {
                try {
                    $logger->info(
                        'Exception has been thrown, rolling back transaction.',
                        $message,
                        $exception
                    );
                    $connection->rollBack();
                } catch (Throwable $rollBackException) {
                    $logger->info(
                        'Exception has been thrown, however could not rollback the transaction.',
                        $message,
                        $exception
                    );
                }
            }

            throw $exception;
        }

        return $result;
    }
}
