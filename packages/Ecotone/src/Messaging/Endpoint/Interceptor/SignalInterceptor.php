<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint\Interceptor;

use Ecotone\Messaging\Endpoint\ConsumerInterceptor;
use Ecotone\Messaging\Endpoint\ConsumerInterceptorTrait;
use RuntimeException;

/**
 * Class SignalInterceptor
 * @package Ecotone\Messaging\Endpoint\Interceptor
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class SignalInterceptor implements ConsumerInterceptor
{
    use ConsumerInterceptorTrait;
    private bool $shouldBeStopped = false;
    private ?SignalHandlerScope $signalHandlerScope = null;

    /**
     * @inheritDoc
     */
    public function onStartup(): void
    {
        if (! extension_loaded('pcntl')) {
            throw new RuntimeException('pcntl extension need to be loaded in order to catch system signals');
        }
        if ($this->signalHandlerScope) {
            throw new RuntimeException('Signal handler already registered');
        }
        $this->signalHandlerScope = new SignalHandlerScope();

        $this->signalHandlerScope->onTerminationSignal(function () {
            $this->shouldBeStopped = true;
        });
    }

    public function onShutdown(): void
    {
        if ($this->signalHandlerScope) {
            $this->signalHandlerScope->release();
            $this->signalHandlerScope = null;
        }
    }

    /**
     * @inheritDoc
     */
    public function shouldBeStopped(): bool
    {
        return $this->shouldBeStopped;
    }
}
