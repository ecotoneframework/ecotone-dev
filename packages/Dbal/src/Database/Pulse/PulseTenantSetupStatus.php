<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database\Pulse;

use Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManagerRegistry;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseSnapshotSection;
use Throwable;

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
 *
 * @internal
 */
final class PulseTenantSetupStatus implements PulseSnapshotSection
{
    /**
     * @param string[] $tenantConnectionReferences
     */
    public function __construct(private ConfiguredMessagingSystem $messagingSystem, private array $tenantConnectionReferences)
    {
    }

    /**
     * @return array{tenantTables: list<array{feature: string, connection: string, initialized: bool|null, error: string|null}>}
     */
    public function snapshot(): array
    {
        $registry = $this->messagingSystem->getServiceFromContainer(DatabaseSetupManagerRegistry::class);
        $tables = [];
        foreach ($this->tenantConnectionReferences as $connectionReferenceName) {
            $manager = $registry->getManagerFor($connectionReferenceName);
            try {
                foreach ($manager->getInitializationStatusOnCurrentConnection() as $feature => $initialized) {
                    $tables[] = ['feature' => $feature, 'connection' => $connectionReferenceName, 'initialized' => $initialized, 'error' => null];
                }
            } catch (Throwable $exception) {
                foreach ($manager->getFeatureNames() as $feature) {
                    $tables[] = ['feature' => $feature, 'connection' => $connectionReferenceName, 'initialized' => null, 'error' => $exception->getMessage()];
                }
            }
        }

        return ['tenantTables' => $tables];
    }
}
