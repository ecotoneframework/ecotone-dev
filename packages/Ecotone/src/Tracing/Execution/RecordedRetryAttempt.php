<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Interceptor\MethodInvocation;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedRetryAttempt implements MethodInvocation
{
    public function __construct(private MethodInvocation $methodInvocation, private RetryAttempts $attempts, private ExecutionRecordingTracer $tracer)
    {
    }

    public function proceed(): mixed
    {
        $attempt = ++$this->attempts->made;
        try {
            return $attempt === 1
                ? $this->methodInvocation->proceed()
                : RecordedStep::of(
                    $this->tracer,
                    'instant retry',
                    fn () => $this->methodInvocation->proceed(),
                    hints: [ExecutionHint::critical(ExecutionHint::RETRY, 'attempt ' . $attempt . ' after ' . $this->attempts->lastFailure . ', by InstantRetryInterceptor::retry')],
                );
        } catch (Throwable $failure) {
            $this->attempts->lastFailure = ExecutionRecordingTracer::shortClassOf($failure::class);

            throw $failure;
        }
    }

    public function cloneCurrentState(): MethodInvocation
    {
        return new self($this->methodInvocation->cloneCurrentState(), $this->attempts, $this->tracer);
    }

    public function getObjectToInvokeOn(): string|object
    {
        return $this->methodInvocation->getObjectToInvokeOn();
    }

    public function getMethodName(): string
    {
        return $this->methodInvocation->getMethodName();
    }

    public function getName(): string
    {
        return $this->methodInvocation->getName();
    }

    public function getArguments(): array
    {
        return $this->methodInvocation->getArguments();
    }
}
