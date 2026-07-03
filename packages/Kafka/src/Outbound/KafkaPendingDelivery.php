<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Outbound;

use Ecotone\Messaging\Channel\AsyncPublishing\DeliveryResult;
use Ecotone\Messaging\Channel\AsyncPublishing\PendingDelivery;
use RdKafka\Producer;

/**
 * licence Enterprise
 */
final class KafkaPendingDelivery implements PendingDelivery
{
    private bool $awaited = false;

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
        $this->awaited = true;
        $this->producer->flush($this->timeoutInMilliseconds);

        return $this->deliveryTracker->collectResult($this->deliveryIds, $this->channelName);
    }

    public function isAwaited(): bool
    {
        return $this->awaited;
    }
}
