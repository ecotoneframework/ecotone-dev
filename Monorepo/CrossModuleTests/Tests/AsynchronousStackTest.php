<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Monorepo\ExampleApp\Common\Domain\Order\Command\PlaceOrder;
use Monorepo\ExampleApp\Common\Domain\Order\ShippingAddress;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\ExampleAppCaseTrait;
use Monorepo\ExampleApp\Symfony\Kernel;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;

final class AsynchronousStackTest extends FullAppTestCase
{
    use ExampleAppCaseTrait;

    public function executeForSymfony(ContainerInterface $container, \Symfony\Component\HttpKernel\Kernel $kernel): void
    {
        $configuration = $container->get(Configuration::class);
        /** @var QueryBus $queryBus */
        $queryBus = $container->get(QueryBus::class);
        $commandBus = $container->get(CommandBus::class);

        $this->placeOrder($commandBus, $configuration);

        $this->assertCount(0, $queryBus->sendWithRouting('getMessages'));

        self::runConsumerForSymfony('notifications', $kernel);

        $this->assertCount(1, $queryBus->sendWithRouting('getMessages'));
    }

    public function executeForLaravel(ContainerInterface $container, LaravelKernel $kernel): void
    {
        $configuration = $container->get(Configuration::class);
        /** @var QueryBus $queryBus */
        $queryBus = $container->get(QueryBus::class);
        $commandBus = $container->get(CommandBus::class);

        $this->placeOrder($commandBus, $configuration);

        $this->assertCount(0, $queryBus->sendWithRouting('getMessages'));

        self::runConsumerForLaravel('notifications');

        $this->assertCount(1, $queryBus->sendWithRouting('getMessages'));
    }

    public function executeForLiteApplication(ContainerInterface $container): void
    {
        $configuration = $container->get(Configuration::class);
        /** @var QueryBus $queryBus */
        $queryBus = $container->get(QueryBus::class);
        /** @var ConfiguredMessagingSystem $messagingSystem */
        $messagingSystem = $container->get(ConfiguredMessagingSystem::class);

        $this->placeOrder($messagingSystem->getCommandBus(), $configuration);

        $this->assertCount(0, $queryBus->sendWithRouting('getMessages'));

        self::runConsumerForMessaging('notifications', $messagingSystem);

        $this->assertCount(1, $queryBus->sendWithRouting('getMessages'));
    }

    public function executeForLite(ConfiguredMessagingSystem $messagingSystem): void
    {
        $configuration = $messagingSystem->getServiceFromContainer(Configuration::class);
        /** @var QueryBus $queryBus */
        $queryBus = $messagingSystem->getServiceFromContainer(QueryBus::class);

        $this->placeOrder($messagingSystem->getCommandBus(), $configuration);

        $this->assertCount(0, $queryBus->sendWithRouting('getMessages'));

        self::runConsumerForMessaging('notifications', $messagingSystem);

        $this->assertCount(1, $queryBus->sendWithRouting('getMessages'));
    }

    private function placeOrder(mixed $commandBus, Configuration $configuration): void
    {
        $commandBus->send(
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