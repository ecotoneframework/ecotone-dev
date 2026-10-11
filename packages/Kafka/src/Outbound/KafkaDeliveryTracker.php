<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Outbound;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Channel\DeliveryConfirmation\DeliveryResult;
use Ecotone\Messaging\Channel\DeliveryConfirmation\FailedDelivery;
use RdKafka\Message as KafkaMessage;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class KafkaDeliveryTracker
{
    /** @var array<string, Message> */
    private array $inFlightMessages = [];

    /** @var array<string, string> */
    private array $deliveryFailures = [];

    private int $nextDeliveryId = 0;

    public function trackInFlight(Message $message): string
    {
        $deliveryId = (string) $this->nextDeliveryId++;
        $this->inFlightMessages[$deliveryId] = $message;

        return $deliveryId;
    }

    public function recordDeliveryReport(KafkaMessage $kafkaMessage): void
    {
        $deliveryId = $kafkaMessage->opaque;
        if (! is_string($deliveryId) || ! array_key_exists($deliveryId, $this->inFlightMessages)) {
            return;
        }

        if ($kafkaMessage->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            $this->deliveryFailures[$deliveryId] = rd_kafka_err2str($kafkaMessage->err);

            return;
        }

        unset($this->inFlightMessages[$deliveryId]);
    }

    public function discard(string $deliveryId): void
    {
        unset($this->inFlightMessages[$deliveryId], $this->deliveryFailures[$deliveryId]);
    }

    public function collectResult(array $deliveryIds, string $channelName): DeliveryResult
    {
        $failedDeliveries = [];
        foreach ($deliveryIds as $deliveryId) {
            if (array_key_exists($deliveryId, $this->deliveryFailures)) {
                $failedDeliveries[] = new FailedDelivery($this->inFlightMessages[$deliveryId], $this->deliveryFailures[$deliveryId], $channelName);
            } elseif (array_key_exists($deliveryId, $this->inFlightMessages)) {
                $failedDeliveries[] = new FailedDelivery($this->inFlightMessages[$deliveryId], 'Timed out awaiting delivery confirmation from Kafka broker', $channelName);
            }

            unset($this->inFlightMessages[$deliveryId], $this->deliveryFailures[$deliveryId]);
        }

        return $failedDeliveries === [] ? DeliveryResult::successful() : DeliveryResult::withFailedDeliveries($failedDeliveries);
    }
}
