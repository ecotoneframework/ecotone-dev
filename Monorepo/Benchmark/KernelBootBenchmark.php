<?php

namespace Monorepo\Benchmark;

use PhpBench\Benchmark\Metadata\Annotations\Iterations;
use PhpBench\Benchmark\Metadata\Annotations\Revs;
use PhpBench\Benchmark\Metadata\Annotations\Warmup;
use Psr\Container\ContainerInterface;

/**
 * @Revs(10)
 * @Iterations(5)
 * @Warmup(1)
 */
class KernelBootBenchmark extends FullAppBenchmarkCase
{
    protected function execute(ContainerInterface $container): void
    {
        // do nothing
    }
}