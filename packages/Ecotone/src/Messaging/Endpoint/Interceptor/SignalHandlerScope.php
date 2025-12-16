<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint\Interceptor;

use Closure;
use RuntimeException;

/**
 * licence Apache-2.0
 */
class SignalHandlerScope
{
    /**
     * @var array<int, array<callable>>
     */
    private array $signalHandlers = [];

    /**
     * @var array<int, callable|int|string>
     */
    private array $originalHandlers = [];
    private Closure $handlingClosure;
    private bool $released = false;

    public function __construct()
    {
        $this->handlingClosure = $this->handleSignal(...);
    }

    /**
     * Initialize signal handling by enabling async signals and registering handlers
     */
    public function register(int $signal, callable $signalHandler): void
    {
        if ($this->released) {
            throw new RuntimeException('Cannot register signal handler after releasing SignalHandler');
        }
        if (! extension_loaded('pcntl')) {
            return;
        }
        $previous = pcntl_signal_get_handler($signal);

        if (!isset($this->originalHandlers[$signal])) {
            $this->originalHandlers[$signal] = $previous;
        }

        if (!isset($this->signalHandlers[$signal])) {
            if (\is_callable($previous) && $this->handlingClosure !== $previous) {
                $this->signalHandlers[$signal][] = $previous;
            }
        }

        $this->signalHandlers[$signal][] = $signalHandler;

        pcntl_signal($signal, $this->handlingClosure);
        pcntl_async_signals(true);
    }

    public function onTerminationSignal(callable $signalHandler): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }
        $this->register(SIGINT, $signalHandler);
        $this->register(SIGTERM, $signalHandler);
        $this->register(SIGQUIT, $signalHandler);
    }

    /**
     * Cleanup signal handling by restoring original handlers and async signals state
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        if (! extension_loaded('pcntl')) {
            return;
        }

        foreach ($this->originalHandlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
    }

    /**
     * Handle received signal
     * @param int $signal
     */
    private function handleSignal(int $signal): void
    {
        foreach ($this->signalHandlers[$signal] ?? [] as $handler) {
            $handler($signal);
        }
    }
}
