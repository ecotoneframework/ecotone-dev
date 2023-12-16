<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\DynamicChannel;

use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;

final class DynamicMessageChannel implements PollableChannel
{
    /**
     * @param array{channel: MessageChannel[]|PollableChannel[], name: string} $internalChannels
     */
    public function __construct(
        private MessagingEntrypoint $messagingEntrypoint,
        private ChannelResolver $channelResolver,
        private string $channelNameToResolveSendingMessageChannel,
        private string $channelNameToResolveReceivingMessageChannel,
        private array $internalChannels,
    ) {}

    public function send(Message $message): void
    {
        $channelName = $this->messagingEntrypoint->send(
            /** This need to be removed in order to return the Message correctly (order routing_slip, replyChannel) */
            MessageBuilder::fromMessage($message)
                ->removeHeader(MessageHeaders::ROUTING_SLIP),
            $this->channelNameToResolveSendingMessageChannel
        );
        Assert::notNull($channelName, "Channel name to send message to cannot be null. If you want to skip message sending, return 'nullChannel' instead.");

        $this->resolveChannel($channelName)->send($message);
    }

    public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
    {
        return $this->resolvePollableChannel()
            ->receiveWithTimeout($timeoutInMilliseconds);
    }

    public function receive(): ?Message
    {
        return $this->resolvePollableChannel()
            ->receive();
    }

    private function resolvePollableChannel(): PollableChannel
    {
        $channelToPoll = $this->messagingEntrypoint->send([], $this->channelNameToResolveReceivingMessageChannel);
        Assert::notNull($channelToPoll, "Channel name to poll message from cannot be null. If you want to skip message receiving, return 'nullChannel' instead.");

        $messageChannel = $this->resolveChannel($channelToPoll);
        Assert::isTrue($messageChannel instanceof PollableChannel, "Channel resolved for polling: '{$this->channelNameToResolveReceivingMessageChannel}' must be pollable");

        return $messageChannel;
    }

    private function resolveChannel(mixed $channelName): MessageChannel
    {
        foreach ($this->internalChannels as $internalChannel) {
            if ($internalChannel['name'] === $channelName) {
                return $internalChannel['channel'];
            }
        }

        return $this->channelResolver->resolve($channelName);
    }
}