<?php

declare(strict_types=1);

namespace Ecotone\Redis;

use Ecotone\Enqueue\EnqueueInboundChannelAdapter;
use Enqueue\Redis\RedisContext;
use Predis\Connection\ConnectionException;

final class RedisInboundChannelAdapter extends EnqueueInboundChannelAdapter
{
    public function initialize(): void
    {
        /** @var RedisContext $context */
        $context = $this->connectionFactory->createContext();
        $context->createQueue($this->queueName);
    }

    public function connectionException(): string
    {
        return ConnectionException::class;
    }
}
