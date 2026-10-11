<?php

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
declare(strict_types=1);

namespace Ecotone\Api\Projecting;

use Attribute;

/**
 * Marks a projection as event-streaming based.
 * Event streaming projections consume events directly from streaming channels.
 * This attribute should be combined with #[Projection] attribute.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Streaming
{
    public function __construct(
        public readonly string $channelName,
    ) {
    }
}
