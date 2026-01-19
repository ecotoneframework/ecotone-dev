<?php

namespace Test\Ecotone\DataProtection\Fixture;

class MessageReceiver
{
    private ?object $receivedMessage = null;

    public function withReceivedMessage(object $message): void
    {
        $this->receivedMessage = $message;
    }

    public function receivedMessage(): object
    {
        return $this->receivedMessage;
    }
}
