<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\FrameworkHeaders;

use function is_scalar;

/**
 * licence Apache-2.0
 */
final class MessageSpanAttributes
{
    /**
     * @return array<string, scalar>
     */
    public static function from(Message $message): array
    {
        $attributes = [];
        foreach (FrameworkHeaders::unsetAllFrameworkHeaders($message->getHeaders()->headers()) as $key => $value) {
            if (is_scalar($value)) {
                $attributes[$key] = $value;
            }
        }

        $headers = $message->getHeaders();
        $attributes[MessageHeaders::MESSAGE_ID] = $headers->getMessageId();
        $attributes[MessageHeaders::MESSAGE_CORRELATION_ID] = $headers->getCorrelationId();
        if ($headers->getParentId() !== null) {
            $attributes[MessageHeaders::PARENT_MESSAGE_ID] = $headers->getParentId();
        }
        $attributes[MessageHeaders::TIMESTAMP] = $headers->getTimestamp();

        return $attributes;
    }
}
