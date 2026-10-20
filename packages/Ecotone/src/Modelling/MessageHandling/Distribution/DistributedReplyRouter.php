<?php

declare(strict_types=1);

namespace Ecotone\Modelling\MessageHandling\Distribution;

use Ecotone\Api\Attribute\Header;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\Gateway\DistributedBusHeader;

use function array_key_exists;

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
final class DistributedReplyRouter
{
    /**
     * @param array<string, DistributedServiceMap> $serviceMapsByOutboundChannel
     */
    public function __construct(
        private array $serviceMapsByOutboundChannel,
        private string $thisServiceName,
    ) {
    }

    /**
     * @return array<string>
     */
    public function route(
        #[Header(DistributedBusHeader::DISTRIBUTED_TARGET_SERVICE_NAME)]
        ?string $targetedServiceName,
        #[Header(DistributedBusHeader::DISTRIBUTED_ROUTING_KEY)]
        string $replyToChannelName,
    ): array {
        foreach ($this->serviceMapsByOutboundChannel as $outboundChannel => $serviceMap) {
            if (array_key_exists($targetedServiceName, $serviceMap->commandMapping)) {
                return [$outboundChannel];
            }
        }

        return [];
    }
}
