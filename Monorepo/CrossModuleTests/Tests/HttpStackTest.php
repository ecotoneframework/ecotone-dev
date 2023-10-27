<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Monorepo\ExampleApp\Common\Domain\Order\Command\PlaceOrder;
use Monorepo\ExampleApp\Common\Domain\Order\ShippingAddress;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\UI\OrderController;
use Monorepo\ExampleApp\ExampleAppCaseTrait;
use Monorepo\ExampleApp\Symfony\Kernel;
use Monorepo\ExampleApp\Symfony\Kernel as SymfonyKernel;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Illuminate\Http\Request as LaravelRequest;

final class HttpStackTest extends FullAppTestCase
{
    use ExampleAppCaseTrait;

    public function executeForSymfony(ContainerInterface $container, \Symfony\Component\HttpKernel\Kernel $kernel): void
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

        $this->assertTrue(true);
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

        $this->assertTrue(true);
    }
}