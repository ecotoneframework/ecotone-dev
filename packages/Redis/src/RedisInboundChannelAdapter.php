<?php
declare(strict_types=1);

namespace Ecotone\Redis;

use Ecotone\Enqueue\EnqueueInboundChannelAdapter;
use Enqueue\Redis\RedisContext;

final class RedisInboundChannelAdapter extends EnqueueInboundChannelAdapter
{
    public function initialize(): void
    {
        /** @var RedisContext $context */
        $context = $this->connectionFactory->createContext();
        $context->createQueue($this->queueName);
    }
}
