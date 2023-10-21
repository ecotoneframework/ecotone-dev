<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests;

use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\UI\OrderController;
use Psr\Container\ContainerInterface;

final class OpenTelemetryTracingTest extends FullAppTestCase
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