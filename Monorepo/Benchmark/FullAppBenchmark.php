<?php

namespace Monorepo\Benchmark;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\UI\OrderController;
use Monorepo\ExampleApp\Common\UI\OrderControllerWithoutMessaging;
use Monorepo\ExampleApp\Symfony\Kernel;
use PhpBench\Benchmark\Metadata\Annotations\Iterations;
use PhpBench\Benchmark\Metadata\Annotations\Revs;
use PhpBench\Benchmark\Metadata\Annotations\Warmup;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

/**
 * @Revs(10)
 * @Iterations(5)
 * @Warmup(1)
 */
class FullAppBenchmark
{
    public function bench_symfony_with_ecotone()
    {
        $kernel = new Kernel('prod', false);
        $kernel->boot();
        $container = $kernel->getContainer();

        $messagingSystem = $container->get(ConfiguredMessagingSystem::class);
        $orderController = $container->get(OrderController::class);
        $configuration = $container->get(Configuration::class);

        $this->execute($messagingSystem, $orderController, $configuration->productId());

        $kernel->shutdown();
    }

    public function bench_symfony_without_ecotone()
    {
        $kernel = new Kernel('prod', false);
        $kernel->boot();
        $container = $kernel->getContainer();

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

        $kernel->shutdown();
    }

    public function bench_lite()
    {
        $bootstrap = require __DIR__ . "/../ExampleApp/Lite/app.php";
        $messagingSystem =  $bootstrap();
        $orderController = $messagingSystem->getServiceFromContainer(OrderController::class);
        $configuration = $messagingSystem->getServiceFromContainer(Configuration::class);
        $this->execute($messagingSystem, $orderController, $configuration->productId());
    }

    public function bench_laravel()
    {
        $app = $this->createLaravelApplication();
        $messagingSystem =$app->get(ConfiguredMessagingSystem::class);
        $orderController =$app->get(OrderController::class);
        $configuration = $app->get(Configuration::class);
        $this->execute($messagingSystem, $orderController, $configuration->productId());
    }

    public function createLaravelApplication()
    {
        $app = require __DIR__ . '/../ExampleApp/Laravel/bootstrap/app.php';

        $app->make(\Illuminate\Foundation\Http\Kernel::class)->bootstrap();

        return $app;
    }

    private function execute(ConfiguredMessagingSystem $messagingSystem, OrderController $orderController, string $productId): void
    {
        $orderController->placeOrder(new Request(content: json_encode([
            'orderId' => Uuid::uuid4()->toString(),
            'address' => [
                'street' => 'Washington',
                'houseNumber' => '15',
                'postCode' => '81-221',
                'country' => 'Netherlands'
            ],
            'productId' => $productId
        ])));
    }
}