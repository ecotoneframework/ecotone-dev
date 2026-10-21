<?php

declare(strict_types=1);

namespace App\Graph;

final readonly class Mismatch
{
    /**
     * @param list<string> $services
     */
    public function __construct(public string $kind, public string $message, public array $services = [])
    {
    }
}
