<?php

namespace Test\Ecotone\Messaging\Fixture\Handler;

use Ecotone\Messaging\Channel\InProcessChannel;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\Support\MessageBuilder;
use Ramsey\Uuid\Uuid;

class HandlerRedirectingToChannel implements MessageHandler
{
    public function __construct(private MessageChannel $channel, private bool $requestInProcessReply = false)
    {
    }

    public function handle(Message $message): void
    {
        if ($this->requestInProcessReply) {
            $message = MessageBuilder::fromMessage($message)
                ->setHeader(InProcessChannel::MESSAGE_HEADER_REPLY_ID, Uuid::uuid4()->toString())
                ->build();
            $this->channel->send($message);
        }
        $this->channel->send($message);
    }
}