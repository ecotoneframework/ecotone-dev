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
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
