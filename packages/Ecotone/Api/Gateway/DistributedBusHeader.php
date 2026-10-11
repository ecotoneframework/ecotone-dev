<?php

declare(strict_types=1);

namespace Ecotone\Api\Gateway;

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
interface DistributedBusHeader
{
    public const DISTRIBUTED_TARGET_SERVICE_NAME = 'ecotone.distributed.targetServiceName';
    public const DISTRIBUTED_SOURCE_SERVICE_NAME = 'ecotone.distributed.sourceServiceName';
    public const DISTRIBUTED_ROUTING_KEY = 'ecotone.distributed.routingKey';
    public const DISTRIBUTED_PAYLOAD_TYPE = 'ecotone.distributed.payloadType';
    public const DISTRIBUTED_ROUTING_SLIP_VALUE = 'ecotone.distributed.invoke';
}
