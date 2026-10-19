<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Closure;

/**
 * licence Apache-2.0
 */
final class UntrackedFlowScope implements FlowTrackingScope
{
    public function publishingStepsAfterCommit(Closure $work): mixed
    {
        return $work();
    }

    public function storingSpansOf(Closure $work): mixed
    {
        return $work();
    }
}
