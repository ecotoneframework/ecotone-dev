<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
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
    public function __construct(array $connectionFactories, array $breakConnectionOnCalls = [])
    {
        $this->connectionFactories = $connectionFactories;
        $this->breakConnectionOnCalls = $breakConnectionOnCalls;
    }

    /**
     * Intercept the method call and break connections if configured to do so
     */
    public function breakConnection(MethodInvocation $methodInvocation, Message $message): mixed
    {
        // Get the current call index (0-based)
        $currentCallIndex = $this->callCount;
        $this->callCount++;

        // Determine if we should break the connection for this call
        $shouldBreak = false; // Default behavior is not to break
        if (isset($this->breakConnectionOnCalls[$currentCallIndex])) {
            $shouldBreak = $this->breakConnectionOnCalls[$currentCallIndex];
        }

        // Proceed with the method invocation
        $result = $methodInvocation->proceed();

        // Break the connection after the method has executed if configured to do so
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
                if ($connection->isConnected()) {
                    $connection->close();
                }
            }
        }
    }
}
