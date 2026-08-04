<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Collector;

use Ecotone\Messaging\Channel\AbstractChannelInterceptor;
use Ecotone\Messaging\Channel\ChannelInterceptor;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * licence Apache-2.0
 */
final class MessageCollectorChannelInterceptor extends AbstractChannelInterceptor implements ChannelInterceptor
{
    public function __construct(
        private CollectorStorage $collectorStorage,
        private LoggingGateway $logger
    ) {
    }

    public function preSend(Message $message, MessageChannel $messageChannel): ?Message
    {
        if ($message->getHeaders()->containsKey(MessageHeaders::COLLECTOR_BYPASS)) {
            return MessageBuilder::fromMessage($message)
                ->removeHeader(MessageHeaders::COLLECTOR_BYPASS)
                ->build();
        }

        if ($this->collectorStorage->isEnabled()) {
            $this->collectorStorage->collect($message, $this->logger);

            $message = null;
        }

        return $message;
    }
}
