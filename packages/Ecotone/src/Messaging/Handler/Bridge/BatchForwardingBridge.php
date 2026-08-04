<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Bridge;

use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\BatchSupportingMessageChannel;
use Ecotone\Messaging\Channel\MessageChannelInterceptorAdapter;
use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * licence Enterprise
 */
final class BatchForwardingBridge
{
    public function __construct(
        private PollableChannel $sourceChannel,
        private ChannelResolver $channelResolver,
        private bool $batchForwardingEnabled,
        private int $maxBatchSize,
    ) {
    }

    public function handle(Message $message): ?Message
    {
        if (! $this->batchForwardingEnabled) {
            return $message;
        }

        $targetChannelName = $this->nextRoutingSlipChannel($message);
        if ($targetChannelName === null || ! $this->isPollable($this->channelResolver->resolve($targetChannelName))) {
            return $message;
        }

        $drainedMessages = $this->drainSourceChannel();
        if ($drainedMessages === []) {
            return $message;
        }

        foreach ($this->groupByTargetChannel($message, $drainedMessages) as $groupTargetChannelName => $groupedMessages) {
            $this->forwardGroup((string) $groupTargetChannelName, $groupedMessages, $message);
        }

        return null;
    }

    /**
     * @return Message[]
     */
    private function drainSourceChannel(): array
    {
        $drainedMessages = [];
        while (count($drainedMessages) < $this->maxBatchSize - 1) {
            $nextMessage = $this->sourceChannel->receive();
            if ($nextMessage === null) {
                break;
            }
            $drainedMessages[] = $nextMessage;
        }

        return $drainedMessages;
    }

    /**
     * @param Message[] $drainedMessages
     * @return array<string, Message[]>
     */
    private function groupByTargetChannel(Message $polledMessage, array $drainedMessages): array
    {
        $polledMessageTargetChannelName = $this->nextRoutingSlipChannel($polledMessage);
        $groups = [$polledMessageTargetChannelName => [$polledMessage]];
        foreach ($drainedMessages as $drainedMessage) {
            $targetChannelName = $this->nextRoutingSlipChannel($drainedMessage);
            if ($targetChannelName === null) {
                $this->releaseWithoutForwarding($drainedMessage);

                continue;
            }
            $groups[$targetChannelName][] = $drainedMessage;
        }

        $polledMessageGroup = $groups[$polledMessageTargetChannelName];
        unset($groups[$polledMessageTargetChannelName]);
        $groups[$polledMessageTargetChannelName] = $polledMessageGroup;

        return $groups;
    }

    /**
     * @param Message[] $groupedMessages
     */
    private function forwardGroup(string $targetChannelName, array $groupedMessages, Message $polledMessage): void
    {
        $targetChannel = $this->channelResolver->resolve($targetChannelName);
        $messagesToForward = array_map(fn (Message $groupedMessage) => $this->advanceRoutingSlip($groupedMessage), $groupedMessages);

        if ($this->supportsBatchMessages($targetChannel)) {
            $targetChannel->send(MessageBuilder::withPayload($this->combineIntoBatch($messagesToForward))->build());
        } else {
            foreach ($messagesToForward as $messageToForward) {
                $targetChannel->send($messageToForward);
            }
        }

        foreach ($groupedMessages as $groupedMessage) {
            if ($groupedMessage !== $polledMessage) {
                $this->acknowledge($groupedMessage);
            }
        }
    }

    /**
     * @param Message[] $messages
     */
    private function combineIntoBatch(array $messages): BatchMessage
    {
        $entries = [];
        foreach ($messages as $message) {
            $entries[] = ['payload' => $message->getPayload(), 'headers' => $this->transferableHeaders($message)];
        }

        return BatchMessage::fromEntries($entries);
    }

    /**
     * @return array<string, mixed>
     */
    private function transferableHeaders(Message $message): array
    {
        $headers = $message->getHeaders()->headers();
        if (isset($headers[MessageHeaders::CONSUMER_ACK_HEADER_LOCATION])) {
            unset($headers[$headers[MessageHeaders::CONSUMER_ACK_HEADER_LOCATION]], $headers[MessageHeaders::CONSUMER_ACK_HEADER_LOCATION]);
        }
        unset($headers[MessageHeaders::POLLED_CHANNEL_NAME], $headers[MessageHeaders::CONSUMER_POLLING_METADATA]);

        return $headers;
    }

    private function advanceRoutingSlip(Message $message): Message
    {
        $routingSlipChannels = explode(',', (string) $message->getHeaders()->get(MessageHeaders::ROUTING_SLIP));
        array_shift($routingSlipChannels);
        $messageBuilder = MessageBuilder::fromMessage($message);
        if ($routingSlipChannels === []) {
            $messageBuilder->removeHeader(MessageHeaders::ROUTING_SLIP);
        } else {
            $messageBuilder->setHeader(MessageHeaders::ROUTING_SLIP, implode(',', $routingSlipChannels));
        }

        return $messageBuilder->build();
    }

    private function nextRoutingSlipChannel(Message $message): ?string
    {
        if (! $message->getHeaders()->containsKey(MessageHeaders::ROUTING_SLIP)) {
            return null;
        }
        $routingSlip = (string) $message->getHeaders()->get(MessageHeaders::ROUTING_SLIP);
        if ($routingSlip === '') {
            return null;
        }

        return explode(',', $routingSlip)[0];
    }

    private function isPollable(MessageChannel $channel): bool
    {
        return $this->unwrap($channel) instanceof PollableChannel;
    }

    private function supportsBatchMessages(MessageChannel $channel): bool
    {
        $unwrappedChannel = $this->unwrap($channel);

        return $unwrappedChannel instanceof BatchSupportingMessageChannel && $unwrappedChannel->supportsBatchMessages();
    }

    private function unwrap(MessageChannel $channel): MessageChannel
    {
        if ($channel instanceof MessageChannelInterceptorAdapter) {
            return $channel->getInternalMessageChannel();
        }

        return $channel;
    }

    private function acknowledge(Message $message): void
    {
        $acknowledgementCallback = $this->acknowledgementCallbackOf($message);
        if ($acknowledgementCallback?->isAutoAcked()) {
            $acknowledgementCallback->accept();
        }
    }

    private function releaseWithoutForwarding(Message $message): void
    {
        $this->acknowledgementCallbackOf($message)?->release();
    }

    private function acknowledgementCallbackOf(Message $message): ?AcknowledgementCallback
    {
        $headers = $message->getHeaders();
        if (! $headers->containsKey(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION)) {
            return null;
        }

        return $headers->get($headers->get(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION));
    }
}
