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
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Throwable;

/**
 * licence Enterprise
 */
final class AmqpPendingDelivery implements PendingDelivery
{
    private const REJECTED_FAILURE_REASON = 'Message was rejected (nack) by RabbitMQ instance. Check RabbitMQ server logs.';
    private const TIMED_OUT_FAILURE_REASON = 'Timed out awaiting publisher confirms from RabbitMQ instance.';
    private const CONNECTION_RESET_FAILURE_REASON = 'AMQP connection was reset while awaiting publisher confirms. Delivery confirmation is unknown.';

    private bool $awaited = false;

    private ?DeliveryResult $deliveryResult = null;

    private int $confirmationsEpoch;

    /**
     * @param array<int, array{message: Message, deliveryTag: int, correlationId: string}> $publishRecords
     */
    public function __construct(
        private AmqpContext $context,
        private array $publishRecords,
        private int $timeoutInMilliseconds,
        private string $channelName,
        private AmqpPublisherConfirmations $confirmations,
    ) {
        $this->confirmationsEpoch = $confirmations->getEpoch();
    }

    public function awaitDelivery(): DeliveryResult
    {
        if ($this->deliveryResult !== null) {
            return $this->deliveryResult;
        }

        $this->awaited = true;
        $deadline = microtime(true) + $this->timeoutInMilliseconds / 1000;
        $unsettledFailureReason = self::TIMED_OUT_FAILURE_REASON;

        while (! $this->allRecordsSettled()) {
            if ($this->confirmations->getEpoch() !== $this->confirmationsEpoch) {
                $unsettledFailureReason = self::CONNECTION_RESET_FAILURE_REASON;

                break;
            }

            $remainingSeconds = $deadline - microtime(true);
            if ($remainingSeconds <= 0) {
                break;
            }

            try {
                $this->pumpConfirmations($remainingSeconds);
            } catch (AMQPTimeoutException) {
                continue;
            } catch (Throwable $exception) {
                $unsettledFailureReason = $exception->getMessage();

                break;
            }
        }

        return $this->deliveryResult = $this->collectResult($unsettledFailureReason);
    }

    public function isAwaited(): bool
    {
        return $this->awaited;
    }

    private function allRecordsSettled(): bool
    {
        foreach ($this->publishRecords as $publishRecord) {
            if (! $this->confirmations->isSettled($publishRecord['deliveryTag'])) {
                return false;
            }
        }

        return true;
    }

    private function pumpConfirmations(float $remainingSeconds): void
    {
        if ($this->context instanceof AmqpLibContext) {
            $this->context->getLibChannel()->wait_for_pending_acks_returns($remainingSeconds);
        } elseif ($this->context instanceof AmqpExtContext) {
            $this->context->getExtChannel()->waitForConfirm($remainingSeconds);
        }
    }

    private function collectResult(string $unsettledFailureReason): DeliveryResult
    {
        $failedDeliveries = [];
        foreach ($this->publishRecords as $publishRecord) {
            $returnReason = $this->confirmations->takeReturnReason($publishRecord['correlationId']);
            if ($returnReason !== null) {
                $failedDeliveries[] = new FailedDelivery($publishRecord['message'], $returnReason, $this->channelName);

                continue;
            }

            if ($this->confirmations->takeRejection($publishRecord['deliveryTag'])) {
                $failedDeliveries[] = new FailedDelivery($publishRecord['message'], self::REJECTED_FAILURE_REASON, $this->channelName);

                continue;
            }

            if (! $this->confirmations->isSettled($publishRecord['deliveryTag'])) {
                $failedDeliveries[] = new FailedDelivery($publishRecord['message'], $unsettledFailureReason, $this->channelName);
            }
        }

        return $failedDeliveries === [] ? DeliveryResult::successful() : DeliveryResult::withFailedDeliveries($failedDeliveries);
    }
}
