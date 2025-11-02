<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\MultiTenant;

use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\PollableChannel;
use Interop\Queue\ConnectionFactory;

/**
 * licence Apache-2.0
 */
final class FakeMessageChannelWithConnectionFactory implements PollableChannel
{
    public function __construct(
        public $channelName,
        public ConnectionFactory $connectionFactory,
    ) {

    }

    public function send(Message $message): void
    {
        $this->getContextChannel()->send($message);
    }

    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
    {
        return $this->receive();
    }

    public function receive(): ?Message
    {
        return $this->getContextChannel()->receive();
    }

    public function onConsumerStop(): void
    {
        // No cleanup needed for fake channels
    }

    private function getContextChannel(): PollableChannel
    {
        return $this->connectionFactory->createContext();
    }
}
