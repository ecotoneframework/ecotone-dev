<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability;

use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseFeature;

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
final class DbalDeadLetterPulseFeatures
{
    /**
     * @param object[] $serviceExtensions
     * @return PulseFeature[]
     */
    public static function reportedFor(array $serviceExtensions, DbalConfiguration $dbalConfiguration): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }

        $storage = $dbalConfiguration->deadLetterInMemory ? 'in memory' : 'database, connection ' . $dbalConfiguration->getDeadLetterConnectionReference();
        return [new PulseFeature(
            package: ModulePackageList::DBAL_PACKAGE,
            name: 'Dead letter',
            enabled: $dbalConfiguration->deadLetterEnabled,
            settings: $dbalConfiguration->deadLetterEnabled ? ['Storage' => $storage] : [],
            handlers: [],
            hint: $dbalConfiguration->deadLetterEnabled
                ? sprintf("A message sent to dead letter channel '%s', which the error channel does once a message has used all its retries, is stored (%s) instead of being lost. From there it can be replayed or deleted, on the Dead letters page of Pulse or with ecotone:deadletter:replay.", DbalDeadLetterBuilder::STORE_CHANNEL, $storage)
                : sprintf("A message sent to dead letter channel '%s' after its last retry is not stored, so it cannot be looked at or replayed later.", DbalDeadLetterBuilder::STORE_CHANNEL),
            configuration: 'DbalConfiguration::createWithDefaults()->withDeadLetter(isDeadLetterEnabled: true)',
        )];
    }
}
