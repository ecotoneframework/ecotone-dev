<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\InstantRetry;

use Ecotone\Api\Attribute\Interceptor\Around;
use Ecotone\Api\Attribute\Parameter\Reference;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Exception;
use Interop\Queue\ConnectionFactory;

/**
 * Around interceptor that closes DB connection after handler execution
 */
final class ConnectionClosingInterceptor
{
    /** @var bool[] per-call flags whether to close connection after call */
    private array $closeOnCall;

    private int $callIndex = 0;

    /**
     * @param ConnectionFactory[] $connectionFactories
     * @param bool[]              $closeOnCall e.g. [true, false] means close after first call only
     */
    public function __construct(array $closeOnCall = [true])
    {
        $this->closeOnCall = $closeOnCall;
    }

    #[Around(pointcut: \Ecotone\Api\Gateway\CommandBus::class)]
    public function closeAfter(
        MethodInvocation $methodInvocation,
        Message $message,
        #[Reference] DbalConnectionFactory $connectionFactory
    ): mixed {
        $current = $this->callIndex++;
        try {
            return $methodInvocation->proceed();
        } finally {
            $shouldClose = $this->closeOnCall[$current] ?? false;
            if ($shouldClose) {
                $this->closeAllConnections(
                    $connectionFactory
                );
            }
        }
    }

    private function closeAllConnections(DbalConnectionFactory $connectionFactory): void
    {
        $context = $connectionFactory->createContext();
        $connection = $context->getDbalConnection();

        try {
            $connection->rollBack();
        } catch (Exception) {
        }
        $connection->close();
    }
}
