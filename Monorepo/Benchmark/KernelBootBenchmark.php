<?php

namespace Monorepo\Benchmark;

use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use Psr\Container\ContainerInterface;

#[Warmup(1), Revs(10), Iterations(5)]
class KernelBootBenchmark extends FullAppBenchmarkCase
{
    protected function execute(ContainerInterface $container): void
    {
        // do nothing
    }
}