<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Collector;

use Ecotone\Messaging\Channel\AbstractChannelInterceptor;
use Ecotone\Messaging\Channel\ChannelInterceptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;

final class CollectorChannelInterceptor extends AbstractChannelInterceptor implements ChannelInterceptor
{
    public function __construct(private string $collectedChannel, private Collector $collector) {}

    public function preSend(Message $message, MessageChannel $messageChannel): ?Message
    {
        if ($this->collector->isEnabled()) {
            $this->collector->send($this->collectedChannel, $message);

            $message = null;
        }

        return $message;
    }
}