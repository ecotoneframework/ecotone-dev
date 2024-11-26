<?php

/*
 * licence Apache-2.0
 */

namespace Ecotone\OpenTelemetry;

use function array_filter;

use Ecotone\Messaging\MessageHeaders;

use function in_array;

use OpenTelemetry\SDK\Trace\Span;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;
use Throwable;

class AddSpanEventLogger implements LoggerInterface
{
    use LoggerTrait;

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $attributes = array_filter(
            $context,
            fn ($key) => in_array($key, [MessageHeaders::MESSAGE_ID, MessageHeaders::MESSAGE_CORRELATION_ID, MessageHeaders::PARENT_MESSAGE_ID]),
            ARRAY_FILTER_USE_KEY
        );

        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $exception = $context['exception'];
            $attributes['exceptionMessage'] = $exception->getMessage();
            $attributes['exceptionClass'] = $exception::class;
            $attributes['exceptionTrace'] = $exception->getTraceAsString();
        }

        Span::getCurrent()->addEvent(
            $message,
            $attributes
        );
    }
}
