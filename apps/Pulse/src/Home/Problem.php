<?php

declare(strict_types=1);

namespace App\Home;

final readonly class Problem
{
    public function __construct(
        public Severity $severity,
        public string $kind,
        public string $text,
        public string $href,
    ) {
    }
}
