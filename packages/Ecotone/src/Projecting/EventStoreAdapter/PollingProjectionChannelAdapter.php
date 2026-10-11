<?php

namespace Ecotone\Projecting\EventStoreAdapter;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;

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
class PollingProjectionChannelAdapter implements DefinedObject
{
    public function execute(): bool
    {
        // This is executed by the inbound channel adapter, which then sends the result
        // to the projection's input channel, triggering ProjectingManager::execute()
        return true;
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class);
    }
}
