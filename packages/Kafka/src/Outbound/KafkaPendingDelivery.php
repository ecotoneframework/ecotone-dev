<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Outbound;

use Ecotone\Messaging\Channel\DeliveryConfirmation\DeliveryResult;
use Ecotone\Messaging\Channel\DeliveryConfirmation\PendingDelivery;
use RdKafka\Producer;

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
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class KafkaPendingDelivery implements PendingDelivery
{
    private bool $awaited = false;

    private ?DeliveryResult $deliveryResult = null;

    /**
     * @param string[] $deliveryIds
     */
    public function __construct(
        private Producer $producer,
        private KafkaDeliveryTracker $deliveryTracker,
        private array $deliveryIds,
        private int $timeoutInMilliseconds,
        private string $channelName,
    ) {
    }

    public function awaitDelivery(): DeliveryResult
    {
        if ($this->deliveryResult !== null) {
            return $this->deliveryResult;
        }

        $this->awaited = true;
        $this->producer->flush($this->timeoutInMilliseconds);

        return $this->deliveryResult = $this->deliveryTracker->collectResult($this->deliveryIds, $this->channelName);
    }

    public function isAwaited(): bool
    {
        return $this->awaited;
    }
}
