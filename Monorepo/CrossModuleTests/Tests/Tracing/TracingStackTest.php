<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests\Tracing;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Monorepo\CrossModuleTests\Tests\FullAppTestCase;
use Monorepo\ExampleApp\Common\Domain\Notification\NotificationSubscriber;
use Monorepo\ExampleApp\Common\Domain\Order\Command\PlaceOrder;
use Monorepo\ExampleApp\Common\Domain\Order\Order;
use Monorepo\ExampleApp\Common\Domain\Order\ShippingAddress;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\Infrastructure\Output;
use Monorepo\ExampleApp\ExampleAppCaseTrait;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Psr\Container\ContainerInterface;
use Test\Ecotone\OpenTelemetry\Integration\TracingTest;

final class TracingStackTest extends FullAppTestCase
{
    use ExampleAppCaseTrait;

    public static function skippedPackages(): array
    {
        return ModulePackageList::allPackagesExcept([
            ModulePackageList::ASYNCHRONOUS_PACKAGE,
            ModulePackageList::TRACING_PACKAGE
        ]);
    }

    public function executeForSymfony(ContainerInterface $container, \Symfony\Component\HttpKernel\Kernel $kernel): void
    {
        $configuration = $container->get(Configuration::class);
        /** @var QueryBus $queryBus */
        $queryBus = $container->get(QueryBus::class);
        $commandBus = $container->get(CommandBus::class);
        /** @var InMemoryExporter $exporter */
        $exporter = $container->get(InMemoryExporter::class);

        $this->placeOrder($commandBus, $configuration);
        self::runConsumerForSymfony('notifications', $kernel);
        $this->assertCount(1, $queryBus->sendWithRouting('getMessages'));

        $this->assertTracing($exporter);
    }

    public function executeForLaravel(ContainerInterface $container, LaravelKernel $kernel): void
    {
        $configuration = $container->get(Configuration::class);
        /** @var QueryBus $queryBus */
        $queryBus = $container->get(QueryBus::class);
        $commandBus = $container->get(CommandBus::class);
        /** @var InMemoryExporter $exporter */
        $exporter = $container->get(InMemoryExporter::class);

        $this->placeOrder($commandBus, $configuration);
        self::runConsumerForLaravel('notifications');
        $this->assertCount(1, $queryBus->sendWithRouting('getMessages'));

        $this->assertTracing($exporter);
    }

    public function executeForLiteApplication(ContainerInterface $container): void
    {
        $configuration = $container->get(Configuration::class);
        /** @var QueryBus $queryBus */
        $queryBus = $container->get(QueryBus::class);
        /** @var ConfiguredMessagingSystem $messagingSystem */
        $messagingSystem = $container->get(ConfiguredMessagingSystem::class);
        /** @var InMemoryExporter $exporter */
        $exporter = $messagingSystem->getServiceFromContainer(InMemoryExporter::class);

        $this->placeOrder($messagingSystem->getCommandBus(), $configuration);
        self::runConsumerForMessaging('notifications', $messagingSystem);
        $this->assertCount(1, $queryBus->sendWithRouting('getMessages'));

        $this->assertTracing($exporter);
    }

    public function executeForLite(ConfiguredMessagingSystem $messagingSystem): void
    {
        /** @var Configuration $configuration */
        $configuration = $messagingSystem->getServiceFromContainer(Configuration::class);
        /** @var QueryBus $queryBus */
        $queryBus = $messagingSystem->getServiceFromContainer(QueryBus::class);
        /** @var InMemoryExporter $exporter */
        $exporter = $messagingSystem->getServiceFromContainer(InMemoryExporter::class);

        $this->placeOrder($messagingSystem->getCommandBus(), $configuration);
        self::runConsumerForMessaging('notifications', $messagingSystem);
        $this->assertCount(1, $queryBus->sendWithRouting('getMessages'));

        $this->assertTracing($exporter);
    }

    private function placeOrder(mixed $commandBus, Configuration $configuration): void
    {
        $commandBus->send(
            new PlaceOrder(
                \Ramsey\Uuid\Uuid::uuid4(),
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

    private function assertTracing(InMemoryExporter $exporter): void
    {
        TracingTest::compareTreesByDetails(
            [
                [
                    'details' => ['name' => 'Command Bus'],
                    'children' => [
                        [
                            'details' => ['name' => 'Command Handler: ' . Order::class . '::create'],
                            'children' => []
                        ],
                        [
                            'details' => ['name' => 'Event Bus'],
                            'children' => []
                        ],
                        [
                            'details' => ['name' => 'Sending to Channel: delivery'],
                            'children' => []
                        ],
                        [
                            'details' => ['name' => 'Sending to Channel: notifications'],
                            'children' => [
                                [
                                    'details' => ['name' => 'Receiving from channel: notifications'],
                                    'children' => [
                                        [
                                            'details' => ['name' => 'Event Handler: ' . NotificationSubscriber::class . '::whenOrderWasPlaced'],
                                            'children' => []
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                [
                    'details' => ['name' => 'Query Bus'],
                    'children' => [
                        [
                            'details' => ['name' => 'Query Handler: ' . Output::class . '::getMessages'],
                            'children' => []
                        ]
                    ]
                ]
            ],
            TracingTest::buildTree($exporter)
        );
    }
}