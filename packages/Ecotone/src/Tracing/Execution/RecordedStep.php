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
    /**
     * @param list<ExecutionHint> $hints
     */
    public static function of(ExecutionRecordingTracer $tracer, string $name, Closure $call, ?Closure $outcome = null, array $hints = []): mixed
    {
        $step = $tracer->startStep($name, hints: $hints);
        try {
            $result = $call();
        } catch (Throwable $failure) {
            if ($step !== null) {
                $tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $described = $outcome === null ? '' : $outcome($result);
            $described instanceof ExecutionHint ? $tracer->endStep($step, '', [$described]) : $tracer->endStep($step, $described);
        }

        return $result;
    }
}
