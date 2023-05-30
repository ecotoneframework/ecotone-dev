<?php

namespace Benchmark;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\SymfonyBundle\App\Kernel;

class EcotoneBenchmark
{
    /**
     * @Revs(10)
     * @Iterations(5)
     * @Warmup(1)
     */
    public function bench_kernel_boot_on_prod()
    {
        $kernel = new Kernel('prod', false);
        $kernel->boot();
    }

    /**
     * @Revs(10)
     * @Iterations(5)
     * @Warmup(1)
     */
    public function bench_kernel_boot_on_dev()
    {
        $kernel = new Kernel('dev', false);
        $kernel->boot();
    }

    /**
     * @Revs(10)
     * @Iterations(5)
     * @Warmup(1)
     */
    public function bench_messaging_boot_on_prod()
    {
        $kernel = new Kernel('prod', false);
        $kernel->boot();
        $kernel->getContainer()->get(ConfiguredMessagingSystem::class);
    }

    /**
     * @Revs(10)
     * @Iterations(5)
     * @Warmup(1)
     */
    public function bench_messaging_boot_on_dev()
    {
        $kernel = new Kernel('dev', false);
        $kernel->boot();
        $kernel->getContainer()->get(ConfiguredMessagingSystem::class);
    }
}
