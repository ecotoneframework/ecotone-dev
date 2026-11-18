<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Execution;

use Closure;
use Fiber;
use WeakMap;

/**
 * licence Apache-2.0
 */
final class ExecutionScope
{
    private static ?WeakMap $statesOfProcess = null;
    private static ?WeakMap $statesOfFibers = null;

    /**
     * @template T of object
     * @param Closure(): T $initialState
     * @return T
     */
    public static function stateOf(object $owner, Closure $initialState): object
    {
        $states = self::statesOfCurrentExecution();

        return $states[$owner] ??= $initialState();
    }

    /**
     * @template T of object
     * @param class-string<T> $stateClass
     * @return T|null
     */
    public static function existingStateOf(object $owner, string $stateClass): ?object
    {
        $state = self::statesOfCurrentExecution()[$owner] ?? null;

        return $state instanceof $stateClass ? $state : null;
    }

    public static function current(): object
    {
        return self::statesOfCurrentExecution();
    }

    public static function isConcurrent(): bool
    {
        return self::coroutineContext() !== null || Fiber::getCurrent() !== null;
    }

    /**
     * @return class-string|null
     */
    public static function coroutineChannelClass(): ?string
    {
        if (class_exists(\OpenSwoole\Coroutine::class, false) && \OpenSwoole\Coroutine::getCid() > 0) {
            return \OpenSwoole\Coroutine\Channel::class;
        }
        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0) {
            return \Swoole\Coroutine\Channel::class;
        }

        return null;
    }

    private static function statesOfCurrentExecution(): WeakMap
    {
        $coroutineContext = self::coroutineContext();
        if ($coroutineContext !== null) {
            return $coroutineContext[self::class] ??= new WeakMap();
        }

        $fiber = Fiber::getCurrent();
        if ($fiber !== null) {
            self::$statesOfFibers ??= new WeakMap();

            return self::$statesOfFibers[$fiber] ??= new WeakMap();
        }

        return self::$statesOfProcess ??= new WeakMap();
    }

    private static function coroutineContext(): ?object
    {
        if (class_exists(\OpenSwoole\Coroutine::class, false) && \OpenSwoole\Coroutine::getCid() > 0) {
            return \OpenSwoole\Coroutine::getContext();
        }
        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0) {
            return \Swoole\Coroutine::getContext();
        }

        return null;
    }
}
