<?php

declare(strict_types=1);

namespace Ecotone\Api\Attribute;

use Attribute;

/**
 * licence Enterprise
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class DecisionModel
{
    /**
     * @param string[] $tags
     */
    public function __construct(
        public readonly array $tags = [],
    ) {
    }
}
