<?php

declare(strict_types=1);

namespace Ecotone\Api\Attribute;

use Attribute;
use Closure;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Scheduling\Duration;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
/**
 * licence Apache-2.0
 */
class TimeToLive extends AddHeader
{
    /**
     * @param int|Duration $time if integer is provided it is treated as milliseconds
     */
    public function __construct(
        int|Duration|null $time = null,
        string|Closure|null $expression = null,
        private readonly bool $shouldReplaceExistingHeader = true
    ) {
        parent::__construct(MessageHeaders::TIME_TO_LIVE, $time instanceof Duration ? $time->inMilliseconds() : $time, $expression);
    }

    public function shouldReplaceExistingHeader(): bool
    {
        return $this->shouldReplaceExistingHeader;
    }
}
