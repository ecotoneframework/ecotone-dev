<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\CommonEventSourcing\Command\RegisterProduct;
use Monorepo\ExampleApp\CommonEventSourcing\PriceChange;
use Monorepo\ExampleApp\Symfony\Kernel as SymfonyKernel;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;

final class EventSourcingStackTest extends FullAppTestCase
{
    public static function skippedPackages(): array
    {
        return ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]);
    }

    public static function namespacesToLoad(): array
    {
        return ['Monorepo\ExampleApp\CommonEventSourcing'];
    }

    public function executeForSymfony(ContainerInterface $container, SymfonyKernel $kernel): void
    {
        $this->executeTestScenario(
            $container->get(CommandBus::class),
            $container->get(QueryBus::class)
        );
    }

    private function executeTestScenario(CommandBus $commandBus, QueryBus $queryBus): void
    {
        $productId = Uuid::uuid4()->toString();
        $commandBus->send(new RegisterProduct($productId, 100));

        Assert::assertEquals([new PriceChange(100, 0)], $commandBus->sendWithRouting('product.getPriceChange', $productId), 'Price change should equal to 0 after registration');

        $commandBus->getCommandBus()->send(new ChangePrice($productId, 120));

        Assert::assertEquals([new PriceChange(100, 0), new PriceChange(120, 20)], $queryBus->sendWithRouting('product.getPriceChange', $productId), 'Price change should equal to 0 after registration');
    }

    public function executeForLaravel(ContainerInterface $container, LaravelKernel $kernel): void
    {
        $this->executeTestScenario(
            $container->get(CommandBus::class),
            $container->get(QueryBus::class)
        );
    }

    public function executeForLiteApplication(ContainerInterface $container): void
    {
        $this->executeTestScenario(
            $container->get(CommandBus::class),
            $container->get(QueryBus::class)
        );
    }

    public function executeForLite(ConfiguredMessagingSystem $messagingSystem): void
    {
        $this->executeTestScenario(
            $messagingSystem->getCommandBus(),
            $messagingSystem->getQueryBus()
        );
    }
}