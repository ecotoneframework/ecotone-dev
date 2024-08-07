<?php

namespace Ecotone\Messaging\Handler\ServiceActivator;

use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\RealMessageProcessor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageDeliveryException;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;

class MessageProcessorActivator implements MessageHandler
{
    public function __construct(
        private ?MessageChannel $outputChannel,
        private RealMessageProcessor $messageProcessor,
        private ChannelResolver $channelResolver,
        private bool $isReplyRequired,
    ) {
    }

    public function handle(Message $message): void
    {
        $replyMessage = $this->messageProcessor->process($message);

        if (! $replyMessage) {
            if ($this->isReplyRequired) {
                throw MessageDeliveryException::createWithFailedMessage("Requires response but got none. {$this->messageProcessor}", $message);
            }
            return;
        }

        $replyChannel = null;
        if ($this->outputChannel) {
            $replyChannel = $this->outputChannel;
        } else {
            $routingSlip = $replyMessage->getHeaders()->containsKey(MessageHeaders::ROUTING_SLIP) ? $replyMessage->getHeaders()->get(MessageHeaders::ROUTING_SLIP) : '';
            $routingSlipChannels = explode(',', $routingSlip);
            if ($routingSlip) {
                $replyChannel = $this->channelResolver->resolve(array_shift($routingSlipChannels));
                $routingSlip = implode(',', $routingSlipChannels);
                $replyMessage = MessageBuilder::fromMessage($replyMessage)
                    ->setHeader(MessageHeaders::ROUTING_SLIP, $routingSlip)
                    ->build();
            } elseif ($message->getHeaders()->containsKey(MessageHeaders::REPLY_CHANNEL)) {
                $replyChannel = $message->getHeaders()->getReplyChannel();
            }
        }

        if (! $replyChannel) {
            if ($this->isReplyRequired) {
                throw MessageDeliveryException::createWithFailedMessage("Can't process {$message}, no output channel during delivery in {$this->messageProcessor}", $message);
            }
            return;
        }

        $replyChannel->send($replyMessage);
    }
}
