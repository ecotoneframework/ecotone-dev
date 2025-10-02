<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\InstantRetry;

use Psr\Log\AbstractLogger;

final class TestRetryLogger extends AbstractLogger
{
    /** @var array<int, array{level:string,message:string,context:array}> */
    private array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string)$level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }

    /** @return string[] */
    public function infoMessages(): array
    {
        return array_map(
            fn ($r) => $r['message'],
            array_filter($this->records, fn ($r) => $r['level'] === 'info')
        );
    }

    public function containsInfoSubstring(string $needle): bool
    {
        foreach ($this->infoMessages() as $msg) {
            if (stripos($msg, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
