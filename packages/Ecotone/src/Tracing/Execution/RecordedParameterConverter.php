<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Handler\ParameterConverter;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedParameterConverter implements ParameterConverter
{
    public function __construct(private ParameterConverter $parameterConverter, private string $parameterName, private ExecutionRecordingTracer $tracer)
    {
    }

    public function getArgumentFrom(Message $message, ?MethodInvocation $methodInvocation = null): mixed
    {
        $step = $this->tracer->startStep('convert argument ' . $this->parameterName);
        try {
            $argument = $this->parameterConverter->getArgumentFrom($message, $methodInvocation);
        } catch (Throwable $failure) {
            if ($step !== null) {
                $this->tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $this->tracer->endStepWhenSomethingRanInside($step);
        }

        return $argument;
    }
}
