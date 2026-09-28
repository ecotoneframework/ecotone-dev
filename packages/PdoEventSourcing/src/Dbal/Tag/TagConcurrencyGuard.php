<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\RetryableException;
use Ecotone\Messaging\Support\ConcurrencyException;

/**
 * licence Enterprise
 */
final class TagConcurrencyGuard
{
    public static function run(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RetryableException $exception) {
            throw new ConcurrencyException($exception->getMessage(), 0, $exception);
        } catch (DriverExceptionInterface $exception) {
            if ($exception->getCode() === 1020 || $exception->getSQLState() === '55P03') {
                throw new ConcurrencyException($exception->getMessage(), 0, $exception);
            }

            throw $exception;
        }
    }
}
