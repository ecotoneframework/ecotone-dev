<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use function array_pop;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageChannel;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Messaging\Channel\ChannelInterceptor;
use Ecotone\Messaging\Support\MessageBuilder;
use Throwable;

/**
 * licence Apache-2.0
 */
final class TracingChannelInterceptor implements ChannelInterceptor
{
    /** @var ActiveSpan[] */
    private array $openSendSpans = [];

    public function __construct(
        private string $channelName,
        private Tracer $tracer,
        private MessageBuilder $messageBuilder,
    ) {
    }

    public function preSend(Message $message, MessageChannel $messageChannel): ?Message
    {
        $span = $this->tracer->startSpan('send ' . $this->channelName, SpanKind::Producer, [
            ...MessageSpanAttributes::from($message),
            SpanAttribute::MESSAGING_OPERATION_TYPE => SpanAttribute::OPERATION_SEND,
            SpanAttribute::MESSAGING_DESTINATION_NAME => $this->channelName,
        ]);
        $this->openSendSpans[] = $span;

        return $this->messageBuilder->fromMessage($message)
            ->setHeader(MessageHeaders::TRACEPARENT, $span->context()->toTraceparent())
            ->build();
    }

    public function postSend(Message $message, MessageChannel $messageChannel): void
    {
    }

    public function afterSendCompletion(Message $message, MessageChannel $messageChannel, ?Throwable $exception): bool
    {
        $span = array_pop($this->openSendSpans);
        if ($exception !== null) {
            $span?->fail($exception);
        } else {
            $span?->end();
        }

        return false;
    }

    public function preReceive(MessageChannel $messageChannel): bool
    {
        return true;
    }

    public function afterReceiveCompletion(?Message $message, MessageChannel $messageChannel, ?Throwable $exception): void
    {
        if ($exception === null || $message === null) {
            return;
        }

        $this->tracer
            ->startSpan('receive ' . $this->channelName, SpanKind::Consumer, [
                ...MessageSpanAttributes::from($message),
                SpanAttribute::MESSAGING_OPERATION_TYPE => SpanAttribute::OPERATION_PROCESS,
                SpanAttribute::MESSAGING_DESTINATION_NAME => $this->channelName,
            ], TracingInterceptor::remoteParentOf($message))
            ->fail($exception);
    }

    public function postReceive(Message $message, MessageChannel $messageChannel): ?Message
    {
        return $message;
    }
}
