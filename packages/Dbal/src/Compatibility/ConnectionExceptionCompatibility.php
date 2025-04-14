<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Compatibility;

use Doctrine\DBAL\ConnectionException;
use ReflectionClass;

/**
 * @package Ecotone\Dbal\Compatibility
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * Compatibility layer for DBAL 3.x and 4.x ConnectionException methods
 */
final class ConnectionExceptionCompatibility
{
    /**
     * Check if the exception is a "no active transaction" exception
     * In DBAL 3.x, we can call noActiveTransaction() on the exception
     * In DBAL 4.x, this method was removed
     */
    public static function isNoActiveTransactionException(ConnectionException $exception): bool
    {
        // Check if the noActiveTransaction method exists
        if (method_exists($exception, 'noActiveTransaction')) {
            try {
                return $exception->noActiveTransaction();
            } catch (\Error $e) {
                // If there's an error, fall back to checking the message
            }
        }

        // In DBAL 4.x, we need to check the exception message
        return str_contains($exception->getMessage(), 'No active transaction');
    }
}
