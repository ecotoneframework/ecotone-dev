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
use Monorepo\ExampleApp\Common\UI\OrderController;
use Monorepo\ExampleApp\Symfony\Kernel as SymfonyKernel;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Illuminate\Http\Request as LaravelRequest;

final class AsynchronousStackTest extends FullAppTestCase
{
    public function executeForSymfony(ContainerInterface $container, SymfonyKernel $kernel): void
    {
        $configuration = $container->get(Configuration::class);
        /** @var QueryBus $queryBus */
        $queryBus = $container->get(QueryBus::class);
        $commandBus = $container->get(CommandBus::class);

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

        $this->assertCount(0, $queryBus->sendWithRouting('getMessages'));

        self::executeSymfonyConsoleCommand(
            $kernel,
            'ecotone:run',
            ['consumerName' => 'async_channel', '--stopOnFailure' => true, '--executionTimeLimit' => 2000, '--finishWhenNoMessages' => true]
        );

        $this->assertCount(2, $queryBus->sendWithRouting('getMessages'));
    }

    public function executeForLaravel(ContainerInterface $container, LaravelKernel $kernel): void
    {
        $configuration = $container->get(Configuration::class);
        /** @var QueryBus $queryBus */
        $queryBus = $container->get(QueryBus::class);
        $commandBus = $container->get(CommandBus::class);

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

        $this->assertCount(0, $queryBus->sendWithRouting('getMessages'));

        Artisan::call(
            'ecotone:run',
            ['consumerName' => 'async_channel', '--stopOnFailure' => true, '--executionTimeLimit' => 2000, '--finishWhenNoMessages' => true]
        );

        $this->assertCount(2, $queryBus->sendWithRouting('getMessages'));
    }

    public function executeForLiteApplication(ContainerInterface $container): void
    {
        $configuration = $container->get(Configuration::class);
        /** @var QueryBus $queryBus */
        $queryBus = $container->get(QueryBus::class);
        /** @var ConfiguredMessagingSystem $messagingSystem */
        $messagingSystem = $container->get(ConfiguredMessagingSystem::class);

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

        $this->assertCount(0, $queryBus->sendWithRouting('getMessages'));

        $messagingSystem->run(
            'async_channel',
            ExecutionPollingMetadata::createWithFinishWhenNoMessages()
                ->withExecutionTimeLimitInMilliseconds(2000)
        );

        $this->assertCount(2, $queryBus->sendWithRouting('getMessages'));
    }

    public function executeForLite(ConfiguredMessagingSystem $messagingSystem): void
    {
        $configuration = $messagingSystem->getServiceFromContainer(Configuration::class);
        /** @var QueryBus $queryBus */
        $queryBus = $messagingSystem->getServiceFromContainer(QueryBus::class);

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

        $this->assertCount(0, $queryBus->sendWithRouting('getMessages'));

        $messagingSystem->run(
            'async_channel',
            ExecutionPollingMetadata::createWithFinishWhenNoMessages()
                ->withExecutionTimeLimitInMilliseconds(2000)
        );

        $this->assertCount(2, $queryBus->sendWithRouting('getMessages'));
    }
}