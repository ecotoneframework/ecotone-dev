<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Enterprise
 */
final class TagTransactionRequirement
{
    public static function assertActiveForTaggedAppend(Connection $connection): void
    {
        if ($connection->isTransactionActive()) {
            return;
        }

        throw ConfigurationException::create(
            'Appending tagged events (or events with an AppendCondition) requires an active database transaction, '
            . 'so the tag versions and the events commit or roll back together. '
            . 'Enable transactions with DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true) for command handlers, '
            . 'DbalConfiguration::createWithDefaults()->withTransactionOnAsynchronousEndpoints(true) for asynchronous handlers, '
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
