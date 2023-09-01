<?php

namespace Ecotone\Dbal\DbalTransaction;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Handler\Logger\LoggingHandlerBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Enqueue\Dbal\DbalContext;
use Exception;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Class DbalTransactionInterceptor
 * @package Ecotone\Amqp\DbalTransaction
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class DbalTransactionInterceptor
{
    /**
     * @param string[] $connectionReferenceNames
     * @param string[] $disableTransactionOnAsynchronousEndpoints
     */
    public function __construct(private array $connectionReferenceNames, private array $disableTransactionOnAsynchronousEndpoints)
    {
    }

    public function transactional(MethodInvocation $methodInvocation, ?AsynchronousRunningEndpoint $asynchronousRunningEndpoint, ?DbalTransaction $DbalTransaction, #[Reference(LoggingHandlerBuilder::LOGGER_REFERENCE)] LoggerInterface $logger, ReferenceSearchService $referenceSearchService)
    {
        $endpointId = $asynchronousRunningEndpoint?->getEndpointId();

        $connections = [];
        if (! in_array($endpointId, $this->disableTransactionOnAsynchronousEndpoints)) {
            /** @var Connection[] $connections */
            $possibleConnections = array_map(function (string $connectionReferenceName) use ($referenceSearchService) {
                $connectionFactory = CachedConnectionFactory::createFor(new DbalReconnectableConnectionFactory($referenceSearchService->get($connectionReferenceName)));

                /** @var DbalContext $context */
                $context = $connectionFactory->createContext();

                return  $context->getDbalConnection();
            }, $DbalTransaction ? $DbalTransaction->connectionReferenceNames : $this->connectionReferenceNames);

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
            }, ConnectionException::class, $logger, 'Starting Database transaction has failed due to network work, retrying in order to self heal.');
            $logger->info('Database Transaction started');
        }
        try {
            $result = $methodInvocation->proceed();

            foreach ($connections as $connection) {
                try {
                    $connection->commit();
                    $logger->info('Database Transaction committed');
                } catch (PDOException $exception) {
                    /** Handles the case where Mysql did implicit commit, when new creating tables */
                    if (! str_contains($exception->getMessage(), 'There is no active transaction')) {
                        $logger->info(
                            'Failure on committing transaction.',
                            [
                                'exception' => $exception,
                            ]
                        );
                        throw $exception;
                    }

                    $logger->info(
                        'Implicit Commit was detected, skipping manual one.',
                        [
                            'exception' => $exception,
                        ]
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
                        [
                            'exception' => $exception,
                        ]
                    );
                    $connection->rollBack();
                } catch (Throwable $rollBackException) {
                    $logger->info(
                        'Exception has been thrown, however could not rollback the transaction.',
                        [
                            'exception' => $rollBackException,
                        ]
                    );
                }
            }

            throw $exception;
        }

        return $result;
    }
}
