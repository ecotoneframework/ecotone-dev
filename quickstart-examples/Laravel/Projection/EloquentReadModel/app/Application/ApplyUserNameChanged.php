<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Application;

final readonly class ApplyUserNameChanged
{
    public function __construct(
        public string $userId,
        public string $name,
    ) {
    }
}
