<?php

namespace Ecotone\Dbal\DbalTransaction;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Handler\Logger\LoggingHandlerBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\MessageHeaders;
use Enqueue\Dbal\DbalContext;
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

    public function transactional(MethodInvocation $methodInvocation, #[Header(MessageHeaders::CONSUMER_ENDPOINT_ID)] ?string $endpointId, ?DbalTransaction $DbalTransaction, #[Reference(LoggingHandlerBuilder::LOGGER_REFERENCE)] LoggerInterface $logger, ReferenceSearchService $referenceSearchService)
    {
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
            $connection->beginTransaction();
        }
        try {
            $result = $methodInvocation->proceed();

            foreach ($connections as $connection) {
                try {
                    $connection->commit();
                } catch (PDOException $exception) {
                    /** Handles the case where Mysql did implicit commit, when new creating tables */
                    if (! str_contains($exception->getMessage(), 'There is no active transaction')) {
                        throw $exception;
                    }

                    $logger->info('Implicit Commit was detected, skipping manual one.');
                    /** Doctrine hold the state, so it needs to be cleaned */
                    try {
                        $connection->rollBack();
                    } catch (\Exception) {
                    };
                }
            }
        } catch (Throwable $exception) {
            foreach ($connections as $connection) {
                try {
                    $connection->rollBack();
                } catch (Throwable $rollBackException) {
                    $logger->info(sprintf('Exception has been thrown, however could not rollback the transaction due to: %s', $rollBackException->getMessage()));
                }
            }

            throw $exception;
        }

        return $result;
    }
}
