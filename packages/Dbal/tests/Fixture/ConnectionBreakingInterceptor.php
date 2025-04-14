<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;
use Enqueue\Dbal\DbalConnectionFactory;
use Interop\Queue\ConnectionFactory;

/**
 * An interceptor that can break database connections at specific points in the transaction lifecycle
 */
class ConnectionBreakingInterceptor
{
    private array $connectionFactories;
    private array $breakConnectionOnCalls;
    private int $callCount = 0;

    /**
     * @param ConnectionFactory[] $connectionFactories
     * @param array $breakConnectionOnCalls Array of booleans indicating whether to break the connection on each call
     */
    public function __construct(array $connectionFactories, array $breakConnectionOnCalls = []) {
        $this->connectionFactories = $connectionFactories;
        $this->breakConnectionOnCalls = $breakConnectionOnCalls;
    }

    /**
     * Intercept the method call and break connections if configured to do so
     */
    public function breakConnection(MethodInvocation $methodInvocation, Message $message): mixed
    {
        $currentCallIndex = $this->callCount;
        $this->callCount++;

        $shouldBreak = true; // Default behavior
        if (isset($this->breakConnectionOnCalls[$currentCallIndex])) {
            $shouldBreak = $this->breakConnectionOnCalls[$currentCallIndex];
        }

        $result = $methodInvocation->proceed();

        if ($shouldBreak) {
            $this->closeAllConnections();
        }

        return $result;
    }

    /**
     * Close all database connections
     */
    private function closeAllConnections(): void
    {
        foreach ($this->connectionFactories as $connectionFactory) {
            if ($connectionFactory instanceof DbalConnectionFactory || $connectionFactory instanceof EcotoneManagerRegistryConnectionFactory) {
                $context = $connectionFactory->createContext();
                $connection = $context->getDbalConnection();

                // Force the connection to close
                $connection->close();
            }
        }
    }
}
