<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;
use Ecotone\Messaging\Config\ConfigurationException;

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
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class TagTransactionRequirement
{
    public static function assertActiveForTaggedAppend(Connection $connection): void
    {
        if ($connection->isTransactionActive()) {
            return;
        }

        throw ConfigurationException::create(
            'Appending tagged events (or events with an AppendCondition), or saving an aggregate while Dynamic Consistency Boundary is enabled, '
            . 'requires an active database transaction, so the tag versions and the events or the aggregate commit or roll back together. '
            . 'Enable transactions with DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true) for command handlers, '
            . 'DbalConfiguration::createWithDefaults()->withTransactionOnAsynchronousEndpoints(true) for asynchronous handlers, '
            . 'remove #[WithoutDatabaseTransaction] from the handler if it carries one, '
            . 'or begin a transaction yourself around EventStore::appendTo().'
        );
    }

    public static function assertActiveForTagBackfill(Connection $connection): void
    {
        if ($connection->isTransactionActive()) {
            return;
        }

        throw ConfigurationException::create(
            'Backfilling tag indexes (ecotone:event-store:backfill-tags) requires an active database transaction, '
            . 'so the tag versions and the index rows commit or roll back together. '
            . 'Enable it with DbalConfiguration::createWithDefaults()->withTransactionOnConsoleCommands(true).'
        );
    }
}
