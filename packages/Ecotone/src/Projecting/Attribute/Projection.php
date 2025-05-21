<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Attribute;

use Attribute;
use Ecotone\Messaging\Attribute\StreamBasedSource;
use Ecotone\Messaging\MessageHeaders;

#[Attribute]
class Projection extends StreamBasedSource
{
    public function __construct(
        public readonly string  $name,
        public readonly ?string $partitionHeaderName = MessageHeaders::EVENT_AGGREGATE_ID,
        public readonly bool    $disableDefaultProjectionHandler = false,
    ) {
    }
}