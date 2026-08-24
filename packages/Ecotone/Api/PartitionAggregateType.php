<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Api;

use Attribute;
use Ecotone\Messaging\MessageHeaders;

#[Attribute(Attribute::TARGET_PARAMETER)]
class PartitionAggregateType extends Header
{
    public function __construct()
    {
    }

    public function getHeaderName(): string
    {
        return MessageHeaders::EVENT_AGGREGATE_TYPE;
    }
}
