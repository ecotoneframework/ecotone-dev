<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Api;

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
