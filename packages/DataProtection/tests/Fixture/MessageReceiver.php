<?php

namespace Test\Ecotone\DataProtection\Fixture;

class MessageReceiver
{
    private ?object $receivedMessage = null;
    private array $receivedHeaders = [];

    public function withReceived(?object $message, array $headers): void
    {
        $this->receivedMessage = $message;
        $this->receivedHeaders = $headers;
    }

    public function receivedMessage(): ?object
    {
        return $this->receivedMessage;
    }

    public function receivedHeaders(): array
    {
        return $this->receivedHeaders;
    }
}
