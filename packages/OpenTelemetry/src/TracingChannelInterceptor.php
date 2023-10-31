<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use Ecotone\Messaging\Channel\ChannelInterceptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;

use function json_decode;
use function json_encode;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;

use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class TracingChannelInterceptor implements ChannelInterceptor
{
    public const TRACING_CARRIER_HEADER = 'ecotoneTracingCarrier';

    public function __construct(private string $channelName, private TracerProviderInterface $tracerProvider)
    {
    }

    public function preSend(Message $message, MessageChannel $messageChannel): ?Message
    {
        $span = EcotoneSpanBuilder::create($message, 'Sending to Channel: ' . $this->channelName, $this->tracerProvider, SpanKind::KIND_PRODUCER)
            ->startSpan();
        $spanScope = $span->activate();

        $carrier = [];
        TraceContextPropagator::getInstance()->inject($carrier);

        return MessageBuilder::fromMessage($message)
                ->setHeader(self::TRACING_CARRIER_HEADER, json_encode($carrier))
                /** Find a better way to propagate that */
                ->setHeader(MessageHeaders::NON_PROPAGATED_CONTEXT, [
                    $span, $spanScope,
                ])
                ->build();
    }

    public function postSend(Message $message, MessageChannel $messageChannel): void
    {

    }

    public function afterSendCompletion(Message $message, MessageChannel $messageChannel, ?Throwable $exception): bool
    {
        /** @var ScopeInterface $spanScope */
        /** @var SpanInterface $span */
        [$span, $spanScope] = $message->getHeaders()->get(MessageHeaders::NON_PROPAGATED_CONTEXT);
        $spanScope->detach();

        $span->setStatus($exception ? StatusCode::STATUS_ERROR : StatusCode::STATUS_OK);
        $span->end();
        $spanScope->detach();

        return false;
    }

    public function preReceive(MessageChannel $messageChannel): bool
    {
        return true;
    }

    public function afterReceiveCompletion(?Message $message, MessageChannel $messageChannel, ?Throwable $exception): void
    {
        if ($exception !== null && $message !== null) {
            // @TODO test
            $carrier = $message->getHeaders()->containsKey(self::TRACING_CARRIER_HEADER) ? json_decode($message->getHeaders()->get(self::TRACING_CARRIER_HEADER), true) : [];
            $context = TraceContextPropagator::getInstance()->extract($carrier);

            $span = EcotoneSpanBuilder::create($message, 'Asynchronous Channel: ' . $this->channelName, $this->tracerProvider, SpanKind::KIND_CONSUMER)
                ->setParent($context)
                ->startSpan();

            $span->setStatus(StatusCode::STATUS_ERROR);
            $span->end();
        }
    }

    public function postReceive(Message $message, MessageChannel $messageChannel): ?Message
    {
        return $message;
    }
}
