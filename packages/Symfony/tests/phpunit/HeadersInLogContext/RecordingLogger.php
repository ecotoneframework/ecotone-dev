<?php

declare(strict_types=1);

namespace Test\HeadersInLogContext;

use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * licence Apache-2.0
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var array<int, array{message: string, context: array<string, mixed>}> */
    private array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['message' => (string) $message, 'context' => $context];
    }

    /** @return array<string, mixed> */
    public function contextOf(string $messageFragment): array
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $messageFragment)) {
                return $record['context'];
            }
        }

        throw new RuntimeException("No log record containing `{$messageFragment}`");
    }
}
