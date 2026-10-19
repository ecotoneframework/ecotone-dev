<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Closure;

/**
 * licence Apache-2.0
 */
interface FlowTrackingScope
{
    public function publishingStepsAfterCommit(Closure $work): mixed;

    public function storingSpansOf(Closure $work): mixed;
}
