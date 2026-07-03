<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\AsyncPublishing;

/**
 * licence Enterprise
 */
final class AsyncPublishingRegistry
{
    /** @var array<int, array{channelName: string, pendingDelivery: PendingDelivery, scopeOwned: bool}> */
    private array $pendingDeliveries = [];

    private int $nextRegistrationIndex = 0;

    private bool $scopeActive = false;

    private bool $shutdownFlushRegistered = false;

    public function openScope(): void
    {
        $this->scopeActive = true;
    }

    public function isScopeActive(): bool
    {
        return $this->scopeActive;
    }

    public function closeScope(): void
    {
        $this->scopeActive = false;
        foreach ($this->pendingDeliveries as $index => $registration) {
            if ($registration['scopeOwned']) {
                unset($this->pendingDeliveries[$index]);
            }
        }
    }

    /**
     * @param PendingDelivery[] $pendingDeliveries
     */
    public function markAsPublisherOwned(array $pendingDeliveries): void
    {
        foreach ($this->pendingDeliveries as $index => $registration) {
            if (in_array($registration['pendingDelivery'], $pendingDeliveries, true)) {
                $this->pendingDeliveries[$index]['scopeOwned'] = false;
            }
        }
    }

    public function awaitAll(): DeliveryResult
    {
        $failedDeliveries = [];
        foreach ($this->pendingDeliveries as $registration) {
            if (! $registration['scopeOwned'] || $registration['pendingDelivery']->isAwaited()) {
                continue;
            }

            $deliveryResult = $registration['pendingDelivery']->awaitDelivery();
            if (! $deliveryResult->isSuccessful()) {
                $failedDeliveries = array_merge($failedDeliveries, $deliveryResult->getFailedDeliveries());
            }
        }

        return $failedDeliveries === [] ? DeliveryResult::successful() : DeliveryResult::withFailedDeliveries($failedDeliveries);
    }

    public function register(string $channelName, PendingDelivery $pendingDelivery): void
    {
        $this->pruneAwaitedDeliveries();
        $this->pendingDeliveries[$this->nextRegistrationIndex++] = ['channelName' => $channelName, 'pendingDelivery' => $pendingDelivery, 'scopeOwned' => $this->scopeActive];

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
