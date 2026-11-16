<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Messaging\Message;
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

    public function recordInvocation(string $role, MethodInvocation $methodInvocation): mixed
    {
        $step = $this->tracer->startStep($role . ' ' . self::shortNameOf($methodInvocation->getName()));
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
