<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;

use function rawurldecode;
use function rawurlencode;
use function str_starts_with;
use function substr;

/**
 * licence Apache-2.0
 */
final class TraceState
{
    private const DESTINATION_ENTRY_PREFIX = 'ecotone=destination:';

    public static function withDestination(string $destination): string
    {
        return self::DESTINATION_ENTRY_PREFIX . rawurlencode($destination);
    }

    public static function destinationOf(Message $message): ?string
    {
        $headers = $message->getHeaders();
        if (! $headers->containsKey(MessageHeaders::TRACESTATE)) {
            return null;
        }
        foreach (explode(',', (string) $headers->get(MessageHeaders::TRACESTATE)) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, self::DESTINATION_ENTRY_PREFIX)) {
                return rawurldecode(substr($entry, strlen(self::DESTINATION_ENTRY_PREFIX)));
            }
        }

        return null;
    }
}
