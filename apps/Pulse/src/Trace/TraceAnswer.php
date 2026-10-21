<?php

declare(strict_types=1);

namespace App\Trace;

final readonly class TraceAnswer
{
    /**
     * @param array<string, mixed>|null $result
     */
    public function __construct(public string $service, public TraceAnswerState $state, public ?array $result, public ?string $failure)
    {
    }

    public function hasResult(): bool
    {
        return $this->result !== null;
    }
}
