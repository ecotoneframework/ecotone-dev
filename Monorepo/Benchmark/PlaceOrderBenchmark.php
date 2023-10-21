<?php

namespace Monorepo\Benchmark;

use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Illuminate\Http\Request as LaravelRequest;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\UI\OrderController;
use Monorepo\ExampleApp\Symfony\Kernel as SymfonyKernel;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Warmup(1), Revs(10), Iterations(5)]
class PlaceOrderBenchmark extends FullAppBenchmarkCase
{
    public function executeForSymfony(ContainerInterface $container, SymfonyKernel $kernel): void
    {
        $configuration = $container->get(Configuration::class);
        $kernel->handle(
            SymfonyRequest::create('/place-order',
                'POST',
                content: json_encode([
                    'orderId' => Uuid::uuid4()->toString(),
                    'address' => [
                        'street' => 'Washington',
                        'houseNumber' => '15',
                        'postCode' => '81-221',
                        'country' => 'Netherlands'
                    ],
                    'productId' => $configuration->productId(),
                ])
            )
        );
    }

    public function executeForLaravel(ContainerInterface $container, LaravelKernel $kernel): void
    {
        $configuration = $container->get(Configuration::class);
        $kernel->handle(
            LaravelRequest::create(
                '/place-order',
                'POST',
                content: json_encode([
                    'orderId' => Uuid::uuid4()->toString(),
                    'address' => [
                        'street' => 'Washington',
                        'houseNumber' => '15',
                        'postCode' => '81-221',
                        'country' => 'Netherlands'
                    ],
                    'productId' => $configuration->productId(),
                ])
            )
        );
    }

    public function executeForLite(ContainerInterface $container): void
    {
        $orderController = $container->get(OrderController::class);
        $configuration = $container->get(Configuration::class);

        $orderController->placeOrder(new SymfonyRequest(content: json_encode([
            'orderId' => Uuid::uuid4()->toString(),
            'address' => [
                'street' => 'Washington',
                'houseNumber' => '15',
                'postCode' => '81-221',
                'country' => 'Netherlands'
            ],
            'productId' => $configuration->productId(),
        ])));
    }
}