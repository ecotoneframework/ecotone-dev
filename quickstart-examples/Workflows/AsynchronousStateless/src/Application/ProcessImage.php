<?php

declare(strict_types=1);

namespace App\Workflow\Application;

final readonly class ProcessImage
{
    public function __construct(public string $path) {}
}