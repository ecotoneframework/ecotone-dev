<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Channel\Collector\CollectorSenderInterceptor;
use Throwable;

/**
 * licence Apache-2.0
 */
final class ExecutionRecorder
{
    public function __construct(private ExecutionRecordingTracer $tracer)
    {
    }

    public function recordAround(string $interceptor, MethodInvocation $methodInvocation): mixed
    {
        $step = $this->tracer->startStep('around ' . self::shortNameOf($interceptor));
        try {
            $result = $methodInvocation->proceed();
        } catch (Throwable $failure) {
            if ($step !== null) {
                $this->tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $this->tracer->endStep($step, '');
        }

        return $result;
    }

    public function recordCollectorSender(string $channelName, MethodInvocation $methodInvocation): mixed
    {
        $this->tracer->openCollector($channelName);
        try {
            return $this->recordAround(CollectorSenderInterceptor::class . '::send for ' . $channelName, $methodInvocation);
        } finally {
            $this->tracer->closeCollector($channelName);
        }
    }

    public function recordInvocation(string $role, MethodInvocation $methodInvocation): mixed
    {
        $step = $this->tracer->startStep($role . ' ' . self::shortNameOf($methodInvocation->getName()), invocation: $role !== RecordExecutionCompilerPass::ROUTER);
        try {
            $result = $methodInvocation->proceed();
        } catch (Throwable $failure) {
            if ($step !== null) {
                $this->tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $this->tracer->endStep($step, self::outcomeOf($result));
        }

        return $result;
    }

    public function recordHousekeeping(string $role, MethodInvocation $methodInvocation): mixed
    {
        $this->tracer->markHousekeeping();

        return $methodInvocation->proceed();
    }

    public function recordFlowTesting(string $interceptor, MethodInvocation $methodInvocation): mixed
    {
        $this->tracer->startFlowTesting();
        try {
            $result = $methodInvocation->proceed();
        } catch (Throwable $failure) {
            $this->tracer->endFlowTesting();

            throw $failure;
        }
        $hiddenFailure = $this->tracer->endFlowTesting();
        if ($hiddenFailure !== null) {
            $this->tracer->recordStep('flow testing ' . self::shortNameOf($interceptor), hints: [ExecutionHint::critical(ExecutionHint::OUTCOME, $hiddenFailure . ' turned into a normal return')]);
        }

        return $result;
    }

    public function observeFlowTesting(string $interceptor, MethodInvocation $methodInvocation): mixed
    {
        try {
            return $methodInvocation->proceed();
        } catch (Throwable $failure) {
            $this->tracer->flowTestingFailed($failure);

            throw $failure;
        }
    }

    public static function shortNameOf(string $classAndMethod): string
    {
        [$class, $method] = explode('::', $classAndMethod, 2) + [1 => ''];

        return ExecutionRecordingTracer::shortClassOf($class) . ($method === '' ? '' : '::' . $method);
    }

    public static function outcomeOf(mixed $result): string
    {
        return match (true) {
            $result === null => 'returns nothing',
            $result instanceof Message => 'returns a message of ' . self::typeOf($result->getPayload()),
            default => 'returns ' . self::typeOf($result),
        };
    }

    public static function typeOf(mixed $value): string
    {
        return is_object($value) ? ExecutionRecordingTracer::shortClassOf($value::class) : get_debug_type($value);
    }
}
