<?php

declare(strict_types=1);

namespace App\Graph;

final readonly class Mismatch
{
    /**
     * @param list<string> $services
     * @param list<string> $where
     */
    public function __construct(public string $kind, public string $text, public string $why, public string $fix, public array $services, public array $where)
    {
    }
}
