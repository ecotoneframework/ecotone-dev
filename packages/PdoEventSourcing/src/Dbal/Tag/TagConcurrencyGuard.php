<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\RetryableException;
use Ecotone\Api\Messaging\ConcurrencyException;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
