<?php

declare(strict_types=1);

namespace App\Catalogue;

final readonly class CancelConcert
{
    public function __construct(public string $concertId)
    {
    }
}
