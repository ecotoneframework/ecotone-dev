<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Illuminate\Support\Facades\Artisan;
use Monorepo\ExampleApp\Common\Domain\Order\Command\PlaceOrder;
use Monorepo\ExampleApp\Common\Domain\Order\ShippingAddress;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Symfony\Kernel as SymfonyKernel;
use Psr\Container\ContainerInterface;

final class ErrorChannelTest extends FullAppTestCase
{
    public function executeForSymfony(ContainerInterface $container, SymfonyKernel $kernel): void
    {
        $configuration = $container->get(Configuration::class);
        /** @var QueryBus $queryBus */
        $queryBus = $container->get(QueryBus::class);

        $this->placeOrder($container->get(CommandBus::class), $configuration);

        $this->assertCount(0, $queryBus->sendWithRouting('getErrorMessages')['defaultDeadLetter']);
        $this->assertCount(0, $queryBus->sendWithRouting('getErrorMessages')['customDeadLetter']);

        self::runConsumerForSymfony('notifications', $kernel, false);

        $this->assertCount(1, $queryBus->sendWithRouting('getErrorMessages')['defaultDeadLetter']);
        $this->assertCount(0, $queryBus->sendWithRouting('getErrorMessages')['customDeadLetter']);

        self::runConsumerForSymfony('delivery', $kernel, false);

        $this->assertCount(1, $queryBus->sendWithRouting('getErrorMessages')['defaultDeadLetter']);
        $this->assertCount(1, $queryBus->sendWithRouting('getErrorMessages')['customDeadLetter']);
    }

    public function executeForLaravel(ContainerInterface $container, LaravelKernel $kernel): void
    {
        $configuration = $container->get(Configuration::class);
        /** @var QueryBus $queryBus */
        $queryBus = $container->get(QueryBus::class);

        $this->placeOrder($container->get(CommandBus::class), $configuration);

        $this->assertCount(0, $queryBus->sendWithRouting('getErrorMessages')['defaultDeadLetter']);
        $this->assertCount(0, $queryBus->sendWithRouting('getErrorMessages')['customDeadLetter']);

        self::runConsumerForLaravel('notifications', false);

        $this->assertCount(1, $queryBus->sendWithRouting('getErrorMessages')['defaultDeadLetter']);
        $this->assertCount(0, $queryBus->sendWithRouting('getErrorMessages')['customDeadLetter']);

        self::runConsumerForLaravel('delivery', false);

        $this->assertCount(1, $queryBus->sendWithRouting('getErrorMessages')['defaultDeadLetter']);
        $this->assertCount(1, $queryBus->sendWithRouting('getErrorMessages')['customDeadLetter']);
    }

    public function executeForLiteApplication(ContainerInterface $container): void
    {
        $configuration = $container->get(Configuration::class);
        /** @var QueryBus $queryBus */
        $queryBus = $container->get(QueryBus::class);
        /** @var ConfiguredMessagingSystem $messagingSystem */
        $messagingSystem = $container->get(ConfiguredMessagingSystem::class);

        $this->placeOrder($container->get(CommandBus::class), $configuration);

        $this->assertCount(0, $queryBus->sendWithRouting('getErrorMessages')['defaultDeadLetter']);
        $this->assertCount(0, $queryBus->sendWithRouting('getErrorMessages')['customDeadLetter']);

        self::runConsumerForMessaging('notifications', $messagingSystem, false);

        $this->assertCount(1, $queryBus->sendWithRouting('getErrorMessages')['defaultDeadLetter']);
        $this->assertCount(0, $queryBus->sendWithRouting('getErrorMessages')['customDeadLetter']);

        self::runConsumerForMessaging('delivery', $messagingSystem, false);

        $this->assertCount(1, $queryBus->sendWithRouting('getErrorMessages')['defaultDeadLetter']);
        $this->assertCount(1, $queryBus->sendWithRouting('getErrorMessages')['customDeadLetter']);
    }

    public function executeForLite(ConfiguredMessagingSystem $messagingSystem): void
    {
        $configuration = $messagingSystem->getServiceFromContainer(Configuration::class);
        /** @var QueryBus $queryBus */
        $queryBus = $messagingSystem->getServiceFromContainer(QueryBus::class);

        $this->placeOrder($messagingSystem->getCommandBus(), $configuration);

        $this->assertCount(0, $queryBus->sendWithRouting('getErrorMessages')['defaultDeadLetter']);
        $this->assertCount(0, $queryBus->sendWithRouting('getErrorMessages')['customDeadLetter']);

        self::runConsumerForMessaging('notifications', $messagingSystem, false);

        $this->assertCount(1, $queryBus->sendWithRouting('getErrorMessages')['defaultDeadLetter']);
        $this->assertCount(0, $queryBus->sendWithRouting('getErrorMessages')['customDeadLetter']);

        self::runConsumerForMessaging('delivery', $messagingSystem, false);

        $this->assertCount(1, $queryBus->sendWithRouting('getErrorMessages')['defaultDeadLetter']);
        $this->assertCount(1, $queryBus->sendWithRouting('getErrorMessages')['customDeadLetter']);
    }

    private function placeOrder(mixed $commandBus, Configuration $configuration): void
    {
        $commandBus->send(
            new PlaceOrder(
                $configuration->failToNotifyOrder(),
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