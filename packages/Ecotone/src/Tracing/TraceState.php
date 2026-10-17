<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;

/**
 * licence Apache-2.0
 */
final class TraceState
{
    private const VENDOR = 'ecotone';
    private const CARRIED = 'carried';

    public static function isCarried(Message $message): bool
    {
        $headers = $message->getHeaders();

        return $headers->containsKey(MessageHeaders::TRACESTATE) && self::entryOf((string) $headers->get(MessageHeaders::TRACESTATE)) === self::CARRIED;
    }

    public static function withCarried(string $tracestate): string
    {
        $entries = array_values(array_filter(array_map('trim', explode(',', $tracestate)), fn (string $entry): bool => $entry !== '' && ! str_starts_with($entry, self::VENDOR . '=')));

        return implode(',', [self::VENDOR . '=' . self::CARRIED, ...$entries]);
    }

    private static function entryOf(string $tracestate): ?string
    {
        foreach (explode(',', $tracestate) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, self::VENDOR . '=')) {
                return substr($entry, strlen(self::VENDOR) + 1);
            }
        }

        return null;
    }
}
