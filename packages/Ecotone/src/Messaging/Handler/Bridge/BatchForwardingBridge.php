<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Bridge;

use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\BatchSupportingMessageChannel;
use Ecotone\Messaging\Channel\CombinedChannelForwardingConfiguration;
use Ecotone\Messaging\Channel\MessageChannelInterceptorAdapter;
use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\MessageBuilder;
use Throwable;

/**
 * licence Enterprise
 */
final class BatchForwardingBridge
{
    public function __construct(
        private PollableChannel $sourceChannel,
        private ChannelResolver $channelResolver,
        private LoggingGateway $logger,
        private bool $batchForwardingEnabled,
        private string $sourceChannelName,
        private CombinedChannelForwardingConfiguration $forwardingConfiguration,
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

        $acknowledgementCallback = $this->acknowledgementCallbackOf($message);
        if ($acknowledgementCallback !== null && ! $acknowledgementCallback->isAutoAcked()) {
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
        $maxBatchSize = $this->forwardingConfiguration->getMaxForwardingBatchSizeFor($this->sourceChannelName);
        $drainedMessages = [];
        while (count($drainedMessages) < $maxBatchSize - 1) {
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
            try {
                $targetChannel->send(
                    MessageBuilder::withPayload($this->combineIntoBatch($messagesToForward))
                        ->setHeader(MessageHeaders::COLLECTOR_BYPASS, true)
                        ->build()
                );
            } catch (Throwable $exception) {
                $this->releaseFailedDelivery($groupedMessages, $polledMessage, $targetChannelName, $exception);

                return;
            }
            $this->acknowledgeAllExcept($groupedMessages, $polledMessage);

            return;
        }

        $bypassCollector = $this->isPollable($targetChannel);
        foreach ($messagesToForward as $messageIndex => $messageToForward) {
            $groupedMessage = $groupedMessages[$messageIndex];
            if ($bypassCollector) {
                $messageToForward = MessageBuilder::fromMessage($messageToForward)
                    ->setHeader(MessageHeaders::COLLECTOR_BYPASS, true)
                    ->build();
            }
            try {
                $targetChannel->send($messageToForward);
            } catch (Throwable $exception) {
                $this->releaseFailedDelivery([$groupedMessage], $polledMessage, $targetChannelName, $exception);

                continue;
            }
            if ($groupedMessage !== $polledMessage) {
                $this->acknowledge($groupedMessage);
            }
        }
    }

    /**
     * @param Message[] $failedMessages
     */
    private function releaseFailedDelivery(array $failedMessages, Message $polledMessage, string $targetChannelName, Throwable $exception): void
    {
        if ($exception instanceof ConnectionException || in_array($polledMessage, $failedMessages, true)) {
            throw $exception;
        }

        foreach ($failedMessages as $failedMessage) {
            $this->acknowledgementCallbackOf($failedMessage)?->release();
            $this->logger->info(
                sprintf('Message with id `%s` released back to source channel, as delivery to `%s` failed. Due to %s', $failedMessage->getHeaders()->getMessageId(), $targetChannelName, $exception->getMessage()),
                $failedMessage,
                ['exception' => $exception],
            );
        }
    }

    /**
     * @param Message[] $groupedMessages
     */
    private function acknowledgeAllExcept(array $groupedMessages, Message $polledMessage): void
    {
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
