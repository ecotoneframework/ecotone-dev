<?php

namespace Monorepo\Benchmark;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Monorepo\ExampleApp\Symfony\Kernel;
use PhpBench\Benchmark\Metadata\Annotations\Iterations;
use PhpBench\Benchmark\Metadata\Annotations\Revs;
use PhpBench\Benchmark\Metadata\Annotations\Warmup;

/**
 * @Revs(10)
 * @Iterations(5)
 * @Warmup(1)
 */
class SymfonyBenchmark
{
    public function bench_kernel_boot_on_prod()
    {
        $kernel = new Kernel('prod', false);
        $kernel->boot();
    }

    public function bench_kernel_boot_on_dev()
    {
        $kernel = new Kernel('dev', false);
        $kernel->boot();
    }

    public function bench_messaging_boot_on_prod()
    {
        $kernel = new Kernel('prod', false);
        $kernel->boot();
        $kernel->getContainer()->get(ConfiguredMessagingSystem::class);
    }

    public function bench_messaging_boot_on_dev()
    {
        $kernel = new Kernel('dev', false);
        $kernel->boot();
        $kernel->getContainer()->get(ConfiguredMessagingSystem::class);
    }
}
