<?php

declare(strict_types=1);

namespace Ecotone\Redis;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapter;
use Ecotone\Enqueue\OutboundMessageConverter;
use Enqueue\Redis\RedisContext;
use Enqueue\Redis\RedisDestination;

final class RedisOutboundChannelAdapter extends EnqueueOutboundChannelAdapter
{
    public function __construct(CachedConnectionFactory $connectionFactory, private string $queueName, bool $autoDeclare, OutboundMessageConverter $outboundMessageConverter)
    {
        parent::__construct(
            $connectionFactory,
            new RedisDestination($queueName),
            $autoDeclare,
            $outboundMessageConverter
        );
    }

    public function initialize(): void
    {
        /** @var RedisContext $context */
        $context = $this->connectionFactory->createContext();
        $context->createQueue($this->queueName);
    }
}
