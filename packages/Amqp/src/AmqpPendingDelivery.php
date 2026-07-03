<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Messaging\Channel\AsyncPublishing\DeliveryResult;
use Ecotone\Messaging\Channel\AsyncPublishing\FailedDelivery;
use Ecotone\Messaging\Channel\AsyncPublishing\PendingDelivery;
use Ecotone\Messaging\Message;
use Enqueue\AmqpExt\AmqpContext as AmqpExtContext;
use Enqueue\AmqpLib\AmqpContext as AmqpLibContext;
use Interop\Amqp\AmqpContext;
use RuntimeException;
use Throwable;

/**
 * licence Enterprise
 */
final class AmqpPendingDelivery implements PendingDelivery
{
    private bool $awaited = false;

    private ?DeliveryResult $deliveryResult = null;

    private int $confirmationsEpoch;

    /**
     * @param Message[] $trackedMessages
     */
    public function __construct(
        private AmqpContext $context,
        private array $trackedMessages,
        private int $timeoutInMilliseconds,
        private string $channelName,
        private ?AmqpExtPublisherConfirmations $extPublisherConfirmations = null,
    ) {
        $this->confirmationsEpoch = $this->extPublisherConfirmations?->getEpoch() ?? 0;
    }

    public function awaitDelivery(): DeliveryResult
    {
        if ($this->deliveryResult !== null) {
            return $this->deliveryResult;
        }

        $this->awaited = true;
        $timeoutInSeconds = $this->timeoutInMilliseconds / 1000;

        try {
            if ($this->extPublisherConfirmations !== null && $this->extPublisherConfirmations->getEpoch() !== $this->confirmationsEpoch) {
                throw new RuntimeException('AMQP connection was reset while awaiting publisher confirms. Delivery confirmation is unknown.');
            }

            if ($this->context instanceof AmqpLibContext) {
                $this->context->getLibChannel()->wait_for_pending_acks_returns($timeoutInSeconds);
            } elseif ($this->context instanceof AmqpExtContext) {
                $this->awaitAllExtConfirmations($timeoutInSeconds);
            }
        } catch (Throwable $exception) {
            return $this->deliveryResult = DeliveryResult::withFailedDeliveries(array_map(
                fn (Message $message) => new FailedDelivery($message, $exception->getMessage(), $this->channelName),
                $this->trackedMessages,
            ));
        }

        return $this->deliveryResult = DeliveryResult::successful();
    }

    private function awaitAllExtConfirmations(float $timeoutInSeconds): void
    {
        if ($this->extPublisherConfirmations === null) {
            $this->context->getExtChannel()->waitForConfirm($timeoutInSeconds);

            return;
        }

        $deadline = microtime(true) + $timeoutInSeconds;
        while ($this->extPublisherConfirmations->hasOutstandingConfirmations()) {
            if ($this->extPublisherConfirmations->getEpoch() !== $this->confirmationsEpoch) {
                throw new RuntimeException('AMQP connection was reset while awaiting publisher confirms. Delivery confirmation is unknown.');
            }

            $remainingSeconds = $deadline - microtime(true);
            if ($remainingSeconds <= 0) {
                throw new RuntimeException('Timed out awaiting publisher confirms from RabbitMQ instance.');
            }

            $this->context->getExtChannel()->waitForConfirm($remainingSeconds);
        }
    }

    public function isAwaited(): bool
    {
        return $this->awaited;
    }
}
