<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Console;

use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\ConsoleParameterOption;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\EventSourcing\Dbal\Tag\TagSchemaVerifier;
use Ecotone\EventSourcing\Tagging\DynamicConsistencyBoundaryDisabled;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;
use Interop\Queue\ConnectionFactory;

/**
 * licence Enterprise
 */
final class TagVerifySchemaConsoleCommand
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
        private TagSchemaVerifier $verifier,
        private bool $dynamicConsistencyBoundaryEnabled,
    ) {
    }

    #[ConsoleCommand('ecotone:event-store:verify-schema', 'Checks the tag tables\' primary keys/collation and, for given legacy streams, that aggregate NOT NULL constraints are relaxed')]
    public function verify(
        #[ConsoleParameterOption] array $legacyStream = [],
    ): ConsoleCommandResultSet {
        if (! $this->dynamicConsistencyBoundaryEnabled) {
            throw DynamicConsistencyBoundaryDisabled::exception();
        }

        $connection = (new DbalReconnectableConnectionFactory($this->connectionFactory))->createContext()->getDbalConnection();

        $problems = [
            ...$this->verifier->verifyTagTables($connection),
            ...$this->verifier->verifyLegacyStreamTables($connection, $legacyStream),
        ];

        if ($problems === []) {
            return ConsoleCommandResultSet::create(['Status'], [['Schema is consistent with the DCB tag-table requirements.']]);
        }

        return ConsoleCommandResultSet::create(['Problem and fix'], array_map(static fn (string $problem) => [$problem], $problems));
    }
}
