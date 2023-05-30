<?php

namespace Benchmark;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\SymfonyBundle\App\Kernel;

class KernelBootBench
{
    /**
     * @Revs(10)
     * @Iterations(5)
     * @Warmup(1)
     */
    public function benchKernelBoot()
    {
        $kernel = new Kernel('prod', false);
        $kernel->boot();
    }

    /**
     * @Revs(10)
     * @Iterations(5)
     * @Warmup(1)
     */
    public function benchKernelBootAndLoadMessagingSystem()
    {
        $kernel = new Kernel('prod', false);
        $kernel->boot();
        $kernel->getContainer()->get(ConfiguredMessagingSystem::class);
    }
}
