<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use Ecotone\Messaging\Channel\ChannelInterceptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\Support\MessageBuilder;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

final class TracingChannelInterceptor implements ChannelInterceptor
{
    public function __construct(private string $channelName, private TracerInterface $tracer)
    {
    }

    public function preSend(Message $message, MessageChannel $messageChannel): ?Message
    {
        $span = EcotoneSpanBuilder::create($message, 'Asynchronous Channel: ' . $this->channelName, $this->tracer, SpanKind::KIND_PRODUCER)
            ->startSpan();
        $span->activate();

        $carrier = [];
        TraceContextPropagator::getInstance()->inject($carrier);

        return MessageBuilder::fromMessage($message)
                ->setMultipleHeaders($carrier)
                ->build();
    }

    public function postSend(Message $message, MessageChannel $messageChannel): void
    {

    }

    public function afterSendCompletion(Message $message, MessageChannel $messageChannel, ?Throwable $exception): bool
    {
        $span = Span::getCurrent();

        $span->setStatus($exception ? StatusCode::STATUS_ERROR : StatusCode::STATUS_OK);
        $span->end();

        return false;
    }

    public function preReceive(MessageChannel $messageChannel): bool
    {
        return true;
    }

    public function afterReceiveCompletion(?Message $message, MessageChannel $messageChannel, ?Throwable $exception): void
    {
    }

    public function postReceive(Message $message, MessageChannel $messageChannel): ?Message
    {
        if ($message instanceof Message) {
            $carrier = $message->getHeaders()->headers();

            /** 4. Here we consume Message from Message Broker, yet this is not span, it's a single moment in time  */

            //            $context = TraceContextPropagator::getInstance()->extract($carrier);
        }

        return $message;
    }
}
