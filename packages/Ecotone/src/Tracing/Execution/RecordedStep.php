<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Closure;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedStep
{
    public static function of(ExecutionRecordingTracer $tracer, string $name, Closure $call, ?Closure $outcome = null): mixed
    {
        $step = $tracer->startStep($name);
        try {
            $result = $call();
        } catch (Throwable $failure) {
            if ($step !== null) {
                $tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $tracer->endStep($step, $outcome === null ? '' : $outcome($result));
        }

        return $result;
    }
}
