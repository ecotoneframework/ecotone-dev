<?php

declare(strict_types=1);

namespace App\Graph;

final readonly class DistributedLink
{
    public function __construct(
        public string $fromService,
        public string $fromNodeId,
        public string $toService,
        public string $toNodeId,
        public string $kind,
        public string $routingKey,
    ) {
    }
}
