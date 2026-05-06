<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Scheduled;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
/**
 * licence Apache-2.0
 */
final class ScheduledMarkerAttribute
{
    public function __construct(public string $value = '')
    {
    }
}
