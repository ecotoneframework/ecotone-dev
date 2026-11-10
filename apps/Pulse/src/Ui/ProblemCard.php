<?php

declare(strict_types=1);

namespace App\Ui;

use App\Home\Severity;

final readonly class ProblemCard
{
    /**
     * @param list<array{text: string, title?: string}> $text
     * @param list<array{label: string, title?: string}> $where
     */
    public function __construct(
        public Severity $severity,
        public string $kind,
        public array $text,
        public array $where,
        public ?string $evidence,
        public string $actionLabel,
        public string $actionHref,
        public ?string $concept,
        public ?string $failureId,
    ) {
    }
}
