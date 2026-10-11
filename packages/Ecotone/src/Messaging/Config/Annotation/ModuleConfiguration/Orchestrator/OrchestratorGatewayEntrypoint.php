<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration\Orchestrator;

use Ecotone\Messaging\Support\Assert;

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
final class OrchestratorGatewayEntrypoint
{
    public function handle(array $routingSlip): array
    {
        foreach ($routingSlip as $index => $channelName) {
            Assert::isTrue(is_string($channelName), 'Orchestrator returned array must contain only strings, but found ' . gettype($channelName) . " at index {$index}");
        }

        return $routingSlip;
    }
}
