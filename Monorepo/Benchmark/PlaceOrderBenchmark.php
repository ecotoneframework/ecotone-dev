<?php

namespace Monorepo\Benchmark;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Illuminate\Http\Request as LaravelRequest;
use Monorepo\ExampleApp\Common\Domain\Order\Command\PlaceOrder;
use Monorepo\ExampleApp\Common\Domain\Order\ShippingAddress;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\UI\OrderController;
use Monorepo\ExampleApp\Symfony\Kernel as SymfonyKernel;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Warmup(1), Revs(10), Iterations(5)]
class PlaceOrderBenchmark extends FullAppBenchmarkCase
{
    public function executeForSymfony(ContainerInterface $container, SymfonyKernel $kernel): void
    {
        $configuration = $container->get(Configuration::class);
        $response = $kernel->handle(
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

        Assert::assertSame(200, $response->getStatusCode(), $response->getContent());
    }

    public function executeForLaravel(ContainerInterface $container, LaravelKernel $kernel): void
    {
        $configuration = $container->get(Configuration::class);
        $response = $kernel->handle(
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

        Assert::assertSame(200, $response->getStatusCode(), $response->getContent());
    }

    public function executeForLiteApplication(ContainerInterface $container): void
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

    public function executeForLite(ConfiguredMessagingSystem $messagingSystem): void
    {
        $configuration = $messagingSystem->getServiceFromContainer(Configuration::class);

        $messagingSystem->getCommandBus()->send(
            new PlaceOrder(
                Uuid::uuid4(),
                $configuration->userId(),
                new ShippingAddress(
                    'Washington',
                    '15',
                    '81-221',
                    'Netherlands'
                ),
                $configuration->productId()
            )
        );
    }
}