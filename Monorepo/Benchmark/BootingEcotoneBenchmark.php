<?php

namespace Monorepo\Benchmark;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Monorepo\ExampleApp\ExampleAppCaseTrait;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;

#[Warmup(1), Revs(10), Iterations(5)]
class BootingEcotoneBenchmark extends Assert
{
    use FullAppBenchmarkCaseTrait;
    use ExampleAppCaseTrait;

    public function executeForSymfony(ContainerInterface $container, \Symfony\Component\HttpKernel\Kernel $kernel): void
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
