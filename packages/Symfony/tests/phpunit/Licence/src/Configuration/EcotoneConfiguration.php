<?php

declare(strict_types=1);

namespace Symfony\App\Licence\Configuration;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\DynamicMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;

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
final class EcotoneConfiguration
{
    #[ServiceContext]
    public function databaseChannel(): array
    {
        return
            [
                DynamicMessageChannelBuilder::createRoundRobin(
                    'notifications',
                    [
                        'queue',
                    ]
                ),
                SimpleMessageChannelBuilder::createQueueChannel('queue'),
            ];
    }
}
