<?php

declare(strict_types=1);

namespace App\Ui;

use App\DeadLetter\DeadLetterFilter;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class DeadLetterDetail
{
    private const ENDPOINT_TARGET_SUFFIX = '.target';
    private const ECOTONE_HEADERS_FIRST = ['id', 'correlationId', 'parentId', 'timestamp', 'contentType', '__type__', 'polledChannelName', 'routingSlip'];

    /**
     * @param array<string, mixed> $entry
     * @param array<string, string>|null $listQuery
     */
    private function __construct(
        public array $entry,
        public Status $status,
        public string $sentence,
        public string $shortName,
        public string $shortClass,
        public string $failedAt,
        public string $location,
        public string $locationTitle,
        public int $position,
        public int $listedWithFailure,
        public int $storedWithFailure,
        public ?string $previousMessageId,
        public ?string $nextMessageId,
        public ?array $listQuery,
        public StackTrace $stackTrace,
        public ?string $headers,
        public ?string $handler,
        public ?string $endpoint,
    ) {
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed>|null $failure
     * @param list<array<string, mixed>> $entriesOfFailure
     */
    public static function of(string $service, array $entry, ?array $failure, array $entriesOfFailure, ?DeadLetterFilter $listFilter): self
    {
        $messageName = (string) ($entry['messageName'] ?? '');
        $exceptionClass = (string) ($entry['exceptionClass'] ?? '');
        $file = (string) ($entry['exceptionFile'] ?? '');
        $line = (int) ($entry['exceptionLine'] ?? 0);
        $failedAt = self::utc($entry['failedAt'] ?? null);
        $siblings = self::siblings($entry, $entriesOfFailure, $listFilter);
        $index = (int) array_search($entry['messageId'] ?? null, array_column($siblings, 'messageId'), true);
        $stackTrace = StackTrace::of(is_string($entry['stackTrace'] ?? null) ? $entry['stackTrace'] : null, $file, $line);

        return new self(
            $entry,
            Status::Warning,
            sprintf(
                'This %s failed in %s at %s and is kept until you replay or delete it: %s.',
                $messageName === '' ? 'unnamed message' : self::shortName($messageName) . ' message',
                $service,
                $failedAt,
                $exceptionClass === '' ? 'an exception of an unreported class' : self::shortName($exceptionClass),
            ),
            self::shortName($messageName),
            self::shortName($exceptionClass),
            $failedAt,
            $file === '' ? '' : basename(str_replace('\\', '/', $file)) . ':' . $line,
            $file === '' ? '' : $file . ':' . $line,
            $index + 1,
            count($siblings),
            max(count($siblings), (int) ($failure['count'] ?? 0)),
            isset($siblings[$index - 1]) ? (string) $siblings[$index - 1]['messageId'] : null,
            isset($siblings[$index + 1]) ? (string) $siblings[$index + 1]['messageId'] : null,
            $listFilter?->toQuery(),
            $stackTrace,
            self::headersOf($entry['headers'] ?? null),
            $stackTrace->handlerMethod(),
            self::endpointOf($entry['headers'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<array<string, mixed>> $entriesOfFailure
     * @return list<array<string, mixed>>
     */
    private static function siblings(array $entry, array $entriesOfFailure, ?DeadLetterFilter $listFilter): array
    {
        $listed = $listFilter === null ? $entriesOfFailure : array_values(array_filter($entriesOfFailure, $listFilter->matches(...)));
        if (! in_array($entry['messageId'] ?? null, array_column($listed, 'messageId'), true)) {
            $listed = $entriesOfFailure;
        }
        if (! in_array($entry['messageId'] ?? null, array_column($listed, 'messageId'), true)) {
            $listed = [$entry];
        }
        usort($listed, static fn (array $left, array $right): int => (string) ($right['failedAt'] ?? '') <=> (string) ($left['failedAt'] ?? ''));

        return $listed;
    }

    private static function endpointOf(mixed $headers): ?string
    {
        $routingSlip = is_array($headers) && is_string($headers['routingSlip'] ?? null) ? $headers['routingSlip'] : '';
        $first = trim(explode(',', $routingSlip)[0]);
        $position = strpos($first, self::ENDPOINT_TARGET_SUFFIX);

        return $position === false || $position === 0 ? null : substr($first, 0, $position);
    }

    private static function headersOf(mixed $headers): ?string
    {
        if (! is_array($headers)) {
            return null;
        }
        $ordered = [];
        foreach (self::ECOTONE_HEADERS_FIRST as $name) {
            if (array_key_exists($name, $headers)) {
                $ordered[$name] = $headers[$name];
            }
        }
        $ordered += $headers;

        return json_encode((object) $ordered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }

    private static function shortName(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }

    private static function utc(mixed $at): string
    {
        if (! is_string($at) || $at === '') {
            return 'an unreported time';
        }
        try {
            return (new DateTimeImmutable($at))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') . ' UTC';
        } catch (Throwable) {
            return $at;
        }
    }
}
