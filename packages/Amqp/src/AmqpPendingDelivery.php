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
use Throwable;

/**
 * licence Enterprise
 */
final class AmqpPendingDelivery implements PendingDelivery
{
    private bool $awaited = false;

    /**
     * @param Message[] $trackedMessages
     */
    public function __construct(
        private AmqpContext $context,
        private array $trackedMessages,
        private int $timeoutInMilliseconds,
        private string $channelName,
    ) {
    }

    public function awaitDelivery(): DeliveryResult
    {
        $this->awaited = true;
        $timeoutInSeconds = $this->timeoutInMilliseconds / 1000;

        try {
            if ($this->context instanceof AmqpLibContext) {
                $this->context->getLibChannel()->wait_for_pending_acks($timeoutInSeconds);
            } elseif ($this->context instanceof AmqpExtContext) {
                $this->context->getExtChannel()->waitForConfirm($timeoutInSeconds);
            }
        } catch (Throwable $exception) {
            return DeliveryResult::withFailedDeliveries(array_map(
                fn (Message $message) => new FailedDelivery($message, $exception->getMessage(), $this->channelName),
                $this->trackedMessages,
            ));
        }

        return DeliveryResult::successful();
    }

    public function isAwaited(): bool
    {
        return $this->awaited;
    }
}
