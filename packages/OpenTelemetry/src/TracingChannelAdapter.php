<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use Ecotone\Messaging\Channel\ChannelInterceptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class TracingChannelAdapter implements ChannelInterceptor
{
    const OPENTRACING_CONTEXT = 'opentracing_context';
    const OPENTRACING_CURRENT_SPAN = 'opentracing_current_span';
    const OPENTRACING_SCOPE = 'opentracing_scope';

    public function __construct(private TracerInterface $tracer) {}

    public function preSend(Message $message, MessageChannel $messageChannel): ?Message
    {
//        $context = $message->getHeaders()->containsKey(self::OPENTRACING_CONTEXT)
//            ? $message->getHeaders()->get(self::OPENTRACING_CONTEXT)
//            : null;

        /**
         *  1. Add correlation id
         *  2. Intercept Buses
         *  3. Framework tracing all possibility to trace all gateway
         */

        $span = EcotoneSpanBuilder::create($message, 'Channel: ' . (string)$messageChannel, $this->tracer)
            ->startSpan();

        $scope = $span->activate();

//        if ($context) {
//            $span = $span->setParent($context);
//        }
//        $span = $span->startSpan();

        return MessageBuilder::fromMessage($message)
                ->setHeader(self::OPENTRACING_CURRENT_SPAN, $span)
                ->setHeader(self::OPENTRACING_SCOPE, $scope)
                ->build();
    }

    public function postSend(Message $message, MessageChannel $messageChannel): void
    {

    }

    public function afterSendCompletion(Message $message, MessageChannel $messageChannel, ?Throwable $exception): void
    {
        /** @var SpanInterface $span */
        $span = $message->getHeaders()->get(self::OPENTRACING_CURRENT_SPAN);
        $span
            ->setStatus($exception ? StatusCode::STATUS_ERROR : StatusCode::STATUS_OK)
            ->end();
        /** @var ScopeInterface $scope */
        $scope = $message->getHeaders()->get(self::OPENTRACING_SCOPE);
        $scope->detach();
    }

    public function preReceive(MessageChannel $messageChannel): bool
    {
        return true;
    }

    public function postReceive(Message $message, MessageChannel $messageChannel): ?Message
    {
        return $message;
    }

    public function afterReceiveCompletion(?Message $message, MessageChannel $messageChannel, ?Throwable $exception): void
    {
    }
}