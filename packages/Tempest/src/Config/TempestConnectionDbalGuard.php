<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config;

use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Apache-2.0
 */
final class TempestConnectionDbalGuard
{
    public static function assertDbalInstalledFor(string $connectionReference): void
    {
        if (class_exists(DbalConnectionReference::class)) {
            return;
        }

        throw ConfigurationException::create(
            "{$connectionReference} hands a Tempest database connection to Ecotone, but ecotone/dbal is not installed. "
            . 'Only the Database capabilities ecotone/dbal brings read a connection reference — DBAL transactions, the Outbox, the Dead Letter, the Document Store, multi-tenant connections and the event store — so without it the connection has nothing to serve. '
            . 'Either install it with `composer require ecotone/dbal`, '
            . 'or remove the TempestConnectionReference from your #[ServiceContext] configuration.'
        );
    }
}
