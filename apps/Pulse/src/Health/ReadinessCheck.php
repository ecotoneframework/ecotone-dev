<?php

declare(strict_types=1);

namespace App\Health;

final readonly class ReadinessCheck
{
    public function __construct(public string $name, public bool $ok, public string $message)
    {
    }
}
