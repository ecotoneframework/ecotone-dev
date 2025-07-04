<?php

namespace Ecotone\Amqp\Transaction;

use AMQPChannel;
use AMQPConnectionException;
use Ecotone\Amqp\AmqpReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Handler\Recoverability\RetryRunner;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\Message;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Throwable;

/**
 * https://www.rabbitmq.com/blog/2011/02/10/introducing-publisher-confirms/
 *
 * The confirm.select method enables publisher confirms on a channel.Â Â Note that a transactional channel cannot be put into confirm mode and a confirm mode channel cannot be made transactional.
 */
/**
 * licence Apache-2.0
 */
class AmqpTransactionInterceptor
{
    private bool $isRunningTransaction = false;

    /**
     * @param array<string, AmqpConnectionFactory> $connectionFactories
     */
    public function __construct(private array $connectionFactories, private LoggingGateway $logger, private RetryRunner $retryRunner)
    {
    }

    public function transactional(
        MethodInvocation $methodInvocation,
        Message $message,
        ?AmqpTransaction $amqpTransaction,
    ) {
        if ($amqpTransaction) {
            $possibleFactories = array_map(fn (string $connectionReferenceName) => $this->connectionFactories[$connectionReferenceName], $amqpTransaction->connectionReferenceNames);
        } else {
            $possibleFactories = $this->connectionFactories;
        }
        /** @var CachedConnectionFactory[] $connectionFactories */
        $connectionFactories = array_map(function (AmqpConnectionFactory $connectionFactory) {
            return CachedConnectionFactory::createFor(new AmqpReconnectableConnectionFactory($connectionFactory));
        }, $possibleFactories);

        if ($this->isRunningTransaction) {
            return $methodInvocation->proceed();
        }

        try {
            $this->isRunningTransaction = true;
            foreach ($connectionFactories as $connectionFactory) {
                $retryStrategy = RetryTemplateBuilder::exponentialBackoffWithMaxDelay(10, 10, 1000)
                    ->maxRetryAttempts(2)
                    ->build();

                $this->retryRunner->runWithRetry(function () use ($connectionFactory) {
                    $connectionFactory->createContext()->getExtChannel()->startTransaction();
                }, $retryStrategy, $message, AMQPConnectionException::class, 'Starting AMQP transaction has failed due to network work, retrying in order to self heal.');
                $this->logger->info(
                    'AMQP transaction started',
                    $message
                );
            }
            try {
                $result = $methodInvocation->proceed();

                foreach ($connectionFactories as $connectionFactory) {
                    $connectionFactory->createContext()->getExtChannel()->commitTransaction();
                }
                $this->logger->info(
                    'AMQP transaction was committed',
                    $message
                );
            } catch (Throwable $exception) {
                foreach ($connectionFactories as $connectionFactory) {
                    /** @var AMQPChannel $extChannel */
                    $extChannel = $connectionFactory->createContext()->getExtChannel();
                    try {
                        $extChannel->rollbackTransaction();
                    } catch (Throwable) {
                    }
                    $extChannel->close(); // Has to be closed in amqp_lib, as if channel is transactional does not allow for sending outside of transaction
                }

                $this->logger->info(
                    'AMQP transaction was roll backed',
                    $message,
                    [
                        'exception' => $exception,
                    ]
                );

                throw $exception;
            }
        } catch (Throwable $exception) {
            $this->isRunningTransaction = false;

            throw $exception;
        }

        $this->isRunningTransaction = false;
        return $result;
    }

    public function isRunningInTransaction(): bool
    {
        return $this->isRunningTransaction;
    }
}
