<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Collector;

use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\Message;

final class CollectedMessage
{
    public function __construct(private string $channelName, private Message $message) {}

    public function getChannelName(): string
    {
        return $this->channelName;
    }

    public function getMessage(): Message
    {
        return $this->message;
    }
}