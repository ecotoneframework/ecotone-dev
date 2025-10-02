<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\Transaction\Nested;

use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Message;
use Stringable;

final class TestCountingLogger implements LoggingGateway
{
    private int $started = 0;
    private int $committed = 0;

    public function info(Stringable|string $message, array|Message|null $context = [], array $additionalContext = []): void
    {
        $msg = (string) $message;
        if ($msg === 'Database Transaction started') {
            $this->started++;
        }
        if ($msg === 'Database Transaction committed') {
            $this->committed++;
        }
    }

    public function error(Stringable|string $message, array|Message|null $context = [], array $additionalContext = []): void
    {
        // no-op for tests
    }

    public function critical(Stringable|string $message, array|Message|null $context = [], array $additionalContext = []): void
    {
        // no-op for tests
    }

    // PSR-3 interface stubs
    public function emergency($message, array $context = []): void
    {
    }
    public function alert($message, array $context = []): void
    {
    }
    public function warning($message, array $context = []): void
    {
    }
    public function notice($message, array $context = []): void
    {
    }
    public function debug($message, array $context = []): void
    {
    }
    public function log($level, $message, array $context = []): void
    {
        if ($level === \Psr\Log\LogLevel::INFO) {
            $msg = (string) $message;
            if ($msg === 'Database Transaction started') {
                $this->started++;
            }
            if ($msg === 'Database Transaction committed') {
                $this->committed++;
            }
        }
    }

    public function getStartedCount(): int
    {
        return $this->started;
    }

    public function getCommittedCount(): int
    {
        return $this->committed;
    }

    public function reset(): void
    {
        $this->started = 0;
        $this->committed = 0;
    }
}
