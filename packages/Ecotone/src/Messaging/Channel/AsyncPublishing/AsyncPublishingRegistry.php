<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\AsyncPublishing;

/**
 * licence Enterprise
 */
final class AsyncPublishingRegistry
{
    /** @var array<int, array{channelName: string, pendingDelivery: PendingDelivery}> */
    private array $pendingDeliveries = [];

    private int $nextRegistrationIndex = 0;

    private bool $shutdownFlushRegistered = false;

    public function register(string $channelName, PendingDelivery $pendingDelivery): void
    {
        $this->pruneAwaitedDeliveries();
        $this->pendingDeliveries[$this->nextRegistrationIndex++] = ['channelName' => $channelName, 'pendingDelivery' => $pendingDelivery];

        if (! $this->shutdownFlushRegistered) {
            register_shutdown_function(fn () => $this->flushUnawaitedDeliveries());
            $this->shutdownFlushRegistered = true;
        }
    }

    public function collectionPoint(): int
    {
        return $this->nextRegistrationIndex;
    }

    /**
     * @return PendingDelivery[]
     */
    public function registeredSince(int $collectionPoint): array
    {
        $registered = [];
        foreach ($this->pendingDeliveries as $index => $registration) {
            if ($index >= $collectionPoint) {
                $registered[] = $registration['pendingDelivery'];
            }
        }

        return $registered;
    }

    public function flushUnawaitedDeliveries(): void
    {
        foreach ($this->pendingDeliveries as $registration) {
            if (! $registration['pendingDelivery']->isAwaited()) {
                $registration['pendingDelivery']->awaitDelivery();
            }
        }
        $this->pendingDeliveries = [];
    }

    private function pruneAwaitedDeliveries(): void
    {
        foreach ($this->pendingDeliveries as $index => $registration) {
            if ($registration['pendingDelivery']->isAwaited()) {
                unset($this->pendingDeliveries[$index]);
            }
        }
    }
}
