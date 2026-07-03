<?php

declare(strict_types=1);

namespace Monorepo\Benchmark\AsyncPublishing;

final class BenchmarkOrderPlaced
{
    public function __construct(public string $orderId)
    {
    }
}
