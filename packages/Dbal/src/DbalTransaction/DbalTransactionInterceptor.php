<?php

namespace Ecotone\Dbal\DbalTransaction;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\Message;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\DbalContext;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;
use Exception;
use Interop\Queue\ConnectionFactory;
use PDOException;
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
            $possibleConnections = array_map(function (ConnectionFactory $connection) {
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
                try {
                    $connection->beginTransaction();
                } catch (Exception $exception) {
                    $connection->close();
                    throw $exception;
                }
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
                    if (str_contains($exception->getMessage(), 'There is no active transaction')) {
                        /** Handles the case where Mysql did implicit commit, when new creating tables */
                        $logger->info(
                            'Implicit Commit was detected, skipping manual one.',
                            $message,
                            ['exception' => $exception],
                        );

                        try {
                            $connection->rollBack();
                        } catch (Exception) {
                        };

                        continue;
                    }

                    throw $exception;
                }
            }
        } catch (Throwable $exception) {
            foreach ($connections as $connection) {
                try {
                    $logger->info(
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
