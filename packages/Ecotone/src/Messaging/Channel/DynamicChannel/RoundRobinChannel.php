<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\DynamicChannel;

use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\PollableChannel;

final class RoundRobinChannel implements PollableChannel
{
    /**
     * @param string[] $channelNamesToSend
     * @param string[] $channelNamesToReceive
     * @param int $currentChannelIndexToSend
     */
    public function __construct(
        private ChannelResolver $channelResolver,
        private array $channelNamesToSend,
        private array $channelNamesToReceive,
        private int $currentChannelIndexToSend = 0,
        private int $currentChannelIndexToReceive = 0,
    ) {}

    public function send(Message $message): void
    {
        $channel = $this->channelResolver->resolve($this->nextToSend());

        $channel->send($message);
    }

    public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
    {
        $channel = $this->channelResolver->resolve($this->nextToReceive());

        return $channel->receiveWithTimeout($timeoutInMilliseconds);
    }

    public function receive(): ?Message
    {
        $channel = $this->channelResolver->resolve($this->nextToReceive());

        return $channel->receive();
    }

    public function nextToSend(): string
    {
        $channelName = $this->channelNamesToSend[$this->currentChannelIndexToSend];

        $this->currentChannelIndexToSend++;

        if ($this->currentChannelIndexToSend >= count($this->channelNamesToSend)) {
            $this->currentChannelIndexToSend = 0;
        }

        return $channelName;
    }

    public function nextToReceive(): string
    {
        $channelName = $this->channelNamesToReceive[$this->currentChannelIndexToReceive];

        $this->currentChannelIndexToReceive++;

        if ($this->currentChannelIndexToReceive >= count($this->channelNamesToReceive)) {
            $this->currentChannelIndexToReceive = 0;
        }

        return $channelName;
    }
}