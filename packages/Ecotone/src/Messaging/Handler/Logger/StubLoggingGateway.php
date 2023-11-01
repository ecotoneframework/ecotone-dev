<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Logger;

use Ecotone\Messaging\Message;

final class StubLoggingGateway implements LoggingGateway
{
    private array $info = [];
    private array $critical = [];

    public static function create(): self
    {
        return new self();
    }

    public function info(string $text, Message $message, ?\Exception $exception = null): void
    {
        $this->info[] = $text;
    }

    public function error(string $text, Message $message, ?\Exception $exception = null): void
    {
        $this->critical[] = $text;
    }

    public function getCritical(): array
    {
        return $this->critical;
    }

    public function getInfo(): array
    {
        return $this->info;
    }
}