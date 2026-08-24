<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Api\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Partitioned
{
    public function __construct(
        public readonly ?string $partitionHeaderName = null,
    ) {
    }
}
