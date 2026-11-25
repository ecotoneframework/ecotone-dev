<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageChannel;
use Ecotone\Messaging\Channel\AbstractChannelInterceptor;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * licence Apache-2.0
 */
final class TraceOfSenderChannelInterceptor extends AbstractChannelInterceptor
{
    public function __construct(private Tracer $tracer, private MessageBuilder $messageBuilder)
    {
    }

    public function preSend(Message $message, MessageChannel $messageChannel): ?Message
    {
        $sender = $this->tracer->currentApplicationSpan();
        if ($sender === null) {
            return $message;
        }

        return $this->messageBuilder->fromMessage($message)->setMultipleHeaders(TracingInterceptor::carrierOf($sender))->build();
    }
}
