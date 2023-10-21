<?php

namespace Monorepo\Benchmark;

use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Monorepo\ExampleApp\Symfony\Kernel;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use Psr\Container\ContainerInterface;

#[Warmup(1), Revs(10), Iterations(5)]
class MessagingBootBenchmark extends FullAppBenchmarkCase
{
    public function executeForSymfony(ContainerInterface $container, Kernel $kernel): void
    {
        $container->get(ConfiguredMessagingSystem::class)->list();
    }

    public function executeForLaravel(ContainerInterface $container, LaravelKernel $kernel): void
    {
        $container->get(ConfiguredMessagingSystem::class)->list();
    }

    public function executeForLiteApplication(ContainerInterface $container): void
    {
        $container->get(ConfiguredMessagingSystem::class)->list();
    }

    public function executeForLite(ConfiguredMessagingSystem $messagingSystem): void
    {
        $messagingSystem->list();
    }
}