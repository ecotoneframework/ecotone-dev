<?php

declare(strict_types=1);

namespace App\Ui;

final readonly class Verdict
{
    public function __construct(public Status $status, public string $sentence, public bool $allClear)
    {
    }
}
