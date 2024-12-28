<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone\Attribute;

use Ecotone\Messaging\Attribute\StreamBasedSource;

#[\Attribute]
class Projection extends StreamBasedSource
{
    public function __construct(
        public readonly string $name
    ) {
    }
}