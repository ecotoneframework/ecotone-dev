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
        try {
            // First try: Check if the noActiveTransaction method exists and is callable
            if (method_exists($exception, 'noActiveTransaction') && is_callable([$exception, 'noActiveTransaction'])) {
                try {
                    $reflection = new \ReflectionMethod($exception, 'noActiveTransaction');
                    if ($reflection->isPublic()) {
                        return $exception->noActiveTransaction();
                    }
                } catch (\Throwable $e) {
                    // If there's an error, fall back to checking the message
                }
            }

            // Second try: Check the exception message for common patterns
            $message = $exception->getMessage();
            $patterns = [
                'No active transaction', // Standard message
                'There is no active transaction', // Alternative wording
                'Transaction not active', // Another alternative
                'not in a transaction', // Another possible message
            ];

            foreach ($patterns as $pattern) {
                if (str_contains($message, $pattern)) {
                    return true;
                }
            }

            // Third try: Check the exception class name for clues
            $className = get_class($exception);
            if (str_contains($className, 'NoActiveTransaction')) {
                return true;
            }

            // If none of the above checks pass, it's probably not a "no active transaction" exception
            return false;
        } catch (\Throwable $e) {
            // If there's any error in our detection logic, default to false
            return false;
        }
    }
}
