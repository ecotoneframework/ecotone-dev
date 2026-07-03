<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\AsyncPublishing;

use Ecotone\Messaging\Future;
use Throwable;

/**
 * licence Enterprise
 */
final class DeliveryFuture implements Future
{
    private bool $resolved = false;

    private ?AsyncPublishingFailedException $failure = null;

    /**
     * @param PendingDelivery[] $pendingDeliveries
     */
    private function __construct(private array $pendingDeliveries)
    {
    }

    /**
     * @param PendingDelivery[] $pendingDeliveries
     */
    public static function forPendingDeliveries(array $pendingDeliveries): self
    {
        return new self($pendingDeliveries);
    }

    public function resolve()
    {
        if ($this->resolved) {
            if ($this->failure !== null) {
                throw $this->failure;
            }

            return;
        }

        $this->resolved = true;
        $failedDeliveries = [];
        try {
            foreach ($this->pendingDeliveries as $pendingDelivery) {
                if ($pendingDelivery->isAwaited()) {
                    continue;
                }

                $deliveryResult = $pendingDelivery->awaitDelivery();
                if (! $deliveryResult->isSuccessful()) {
                    $failedDeliveries = array_merge($failedDeliveries, $deliveryResult->getFailedDeliveries());
                }
            }
        } catch (Throwable $exception) {
            $this->failure = $exception instanceof AsyncPublishingFailedException
                ? $exception
                : new AsyncPublishingFailedException(sprintf('Awaiting delivery confirmation failed: %s', $exception->getMessage()), 0, $exception);

            throw $this->failure;
        }

        if ($failedDeliveries !== []) {
            $this->failure = AsyncPublishingFailedException::withFailedDeliveries($failedDeliveries);

            throw $this->failure;
        }


    }
}
