<?php

declare(strict_types=1);

namespace App\Settings;

final readonly class ServiceRoute
{
    public function __construct(
        public string $service,
        public string $channel,
        public string $destination,
        public string $connection,
    ) {
    }
}
