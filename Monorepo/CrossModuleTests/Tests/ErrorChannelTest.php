<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Api\CommandBus;
use Ecotone\Api\QueryBus;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Monorepo\ExampleApp\Common\Domain\Order\Command\PlaceOrder;
use Monorepo\ExampleApp\Common\Domain\Order\ShippingAddress;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\ExampleAppCaseTrait;
use Monorepo\ExampleApp\Symfony\Kernel;
use Psr\Container\ContainerInterface;

final class ErrorChannelTest extends FullAppTestCase
{
    use ExampleAppCaseTrait;

    public static function modulePackagesToLoad(): array
    {
        return [
            ModulePackageList::TRACING_PACKAGE,
        ];
    }

    public function executeForSymfony(ContainerInterface $container, \Symfony\Component\HttpKernel\Kernel $kernel): void
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