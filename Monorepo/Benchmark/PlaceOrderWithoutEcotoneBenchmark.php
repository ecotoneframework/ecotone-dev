<?php

namespace Monorepo\Benchmark;

use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\UI\OrderControllerWithoutMessaging;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Skip;
use PhpBench\Attributes\Warmup;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

#[Skip, Warmup(1), Revs(10), Iterations(5)]
class PlaceOrderWithoutEcotoneBenchmark extends FullAppBenchmarkCase
{
    protected function execute(ContainerInterface $container): void
    {
        $orderController = $container->get(OrderControllerWithoutMessaging::class);
        $configuration = $container->get(Configuration::class);

        $orderController->placeOrder(new Request(content: json_encode([
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