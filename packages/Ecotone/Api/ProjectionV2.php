<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Api;

use Attribute;
use Ecotone\Messaging\Attribute\StreamBasedSource;

#[Attribute(Attribute::TARGET_CLASS)]
class ProjectionV2 extends StreamBasedSource
{
    public function __construct(
        public readonly string $name,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }
}
