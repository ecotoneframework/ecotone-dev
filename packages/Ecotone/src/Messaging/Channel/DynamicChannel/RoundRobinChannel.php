<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\DynamicChannel;

use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\PollableChannel;

final class RoundRobinChannel implements PollableChannel
{
    /**
     * @param string[] $channelNamesToSend
     * @param string[] $channelNamesToReceive
     * @param int $currentChannelIndexToSend
     * @param array{channel: MessageChannel[]|PollableChannel[], name: string} $internalChannels
     */
    public function __construct(
        private string $channelName,
        private ChannelResolver $channelResolver,
        private array $channelNamesToSend,
        private array $channelNamesToReceive,
        private LoggingGateway $loggingGateway,
        private array $internalChannels,
        private int $currentChannelIndexToSend = 0,
        private int $currentChannelIndexToReceive = 0,
    ) {}

    public function send(Message $message): void
    {
        $channelName = $this->nextToSend();
        $channel = $this->resolveMessageChannel($channelName);
        $this->loggingGateway->info("Decided to send message to `{$channelName}` for `{$this->channelName}`", $message, contextData: ['channel_name' => $this->channelName, 'chosen_channel_name' => $channelName]);

        $channel->send($message);
    }

    public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
    {
        $channelName = $this->nextToReceive();
        $channel = $this->resolveMessageChannel($channelName);
        $message = $channel->receiveWithTimeout($timeoutInMilliseconds);
        $this->loggingGateway->info("Decided to received message from `{$channelName}` for `{$this->channelName}`", $message, contextData: ['channel_name' => $this->channelName, 'chosen_channel_name' => $channelName]);

        return $message;
    }

    public function receive(): ?Message
    {
        $channelName = $this->nextToReceive();
        $channel = $this->resolveMessageChannel($channelName);

        $message = $channel->receive();
        $this->loggingGateway->info("Decided to received message from `{$channelName}` for `{$this->channelName}`", $message, contextData: ['channel_name' => $this->channelName, 'chosen_channel_name' => $channelName]);

        return $message;
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

    private function resolveMessageChannel(string $channelName): MessageChannel
    {
        foreach ($this->internalChannels as $internalChannel) {
            if ($internalChannel['name'] === $channelName) {
                return $internalChannel['channel'];
            }
        }

        return $this->channelResolver->resolve($channelName);
    }
}