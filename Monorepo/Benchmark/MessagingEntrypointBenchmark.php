<?php

namespace Monorepo\Benchmark;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\ServiceActivator;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use Ecotone\Messaging\Gateway\MessagingEntrypointWithHeadersPropagation;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[Warmup(1), Revs(1000), Iterations(5)]
class MessagingEntrypointBenchmark
{
    private ConfiguredMessagingSystem $messagingSystem;
    private MessagingEntrypointWithHeadersPropagation $proxyEntrypoint;
    private MessagingEntrypointService $directEntrypoint;

    public function __construct()
    {
        $this->messagingSystem = EcotoneLite::bootstrap(
            [BenchmarkHandler::class],
            [new BenchmarkHandler()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages()),
        );

        $this->proxyEntrypoint = $this->messagingSystem->getGatewayByName(MessagingEntrypointWithHeadersPropagation::class);
        $this->directEntrypoint = $this->messagingSystem->getServiceFromContainer(MessagingEntrypointService::class);
    }

    public function bench_proxy_entrypoint(): void
    {
        $this->proxyEntrypoint->sendWithHeaders('test', [], 'benchmark_handler');
    }

    public function bench_direct_entrypoint(): void
    {
        $this->directEntrypoint->sendWithHeaders('test', [], 'benchmark_handler');
    }

}

class BenchmarkHandler
{
    #[ServiceActivator('benchmark_handler')]
    public function handle(string $payload): string
    {
        return $payload;
    }
}
