<?php

namespace Monorepo\Benchmark;

use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\UI\OrderController;
use PhpBench\Benchmark\Metadata\Annotations\Iterations;
use PhpBench\Benchmark\Metadata\Annotations\Revs;
use PhpBench\Benchmark\Metadata\Annotations\Warmup;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

/**
 * @Revs(10)
 * @Iterations(5)
 * @Warmup(1)
 */
class PlaceOrderBenchmark extends FullAppBenchmarkCase
{
    protected function execute(ContainerInterface $container): void
    {
        $orderController = $container->get(OrderController::class);
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