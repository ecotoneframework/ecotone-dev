<?php

namespace Monorepo\Benchmark;

use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Monorepo\ExampleApp\Symfony\Kernel;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use Psr\Container\ContainerInterface;

#[Warmup(1), Revs(10), Iterations(5)]
class KernelBootBenchmark extends FullAppBenchmarkCase
{
    public function executeForSymfony(ContainerInterface $container, Kernel $kernel): void
    {
        // do nothing
    }

    public function executeForLaravel(ContainerInterface $container, LaravelKernel $kernel): void
    {
        // do nothing
    }

    public function executeForLite(ContainerInterface $container): void
    {
        // do nothing
    }
}