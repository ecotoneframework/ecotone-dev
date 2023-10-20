<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;

final class EcotoneSpanBuilder
{
    public static function create(Message $context, string $traceName, TracerInterface $tracer, int $type = SpanKind::KIND_SERVER): SpanBuilderInterface
    {
        $userHeaders = MessageHeaders::unsetAllFrameworkHeaders($context->getHeaders()->headers());

        foreach ($userHeaders as $key => $value) {
            if (!TypeDescriptor::createFromVariable($value)->isScalar()) {
                unset($userHeaders[$key]);
            }
        }

        return $tracer
            ->spanBuilder($traceName)
            ->setSpanKind($type)
            ->setAttribute(MessageHeaders::MESSAGE_ID, $context->getHeaders()->getMessageId())
            ->setAttribute(MessageHeaders::MESSAGE_CORRELATION_ID, $context->getHeaders()->getCorrelationId())
            ->setAttribute(MessageHeaders::PARENT_MESSAGE_ID, $context->getHeaders()->getParentId())
            ->setAttribute(MessageHeaders::TIMESTAMP, $context->getHeaders()->getTimestamp())
            ->setAttributes($userHeaders);
    }
}
