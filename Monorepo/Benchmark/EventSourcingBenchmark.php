<?php

namespace Monorepo\Benchmark;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Monorepo\ExampleAppEventSourcing\Common\Command\ChangePrice;
use Monorepo\ExampleAppEventSourcing\Common\Command\RegisterProduct;
use Monorepo\ExampleAppEventSourcing\Common\PriceChange;
use Monorepo\ExampleAppEventSourcing\ExampleAppEventSourcingCaseTrait;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;

#[Warmup(1), Revs(10), Iterations(5)]
class EventSourcingBenchmark extends FullAppBenchmarkCase
{
    use ExampleAppEventSourcingCaseTrait;

    public static function skippedPackages(): array
    {
        return ModulePackageList::allPackagesExcept([
            ModulePackageList::EVENT_SOURCING_PACKAGE,
            ModulePackageList::DBAL_PACKAGE,
            ModulePackageList::JMS_CONVERTER_PACKAGE
        ]);
    }

    public function executeForSymfony(ContainerInterface $container, \Symfony\Component\HttpKernel\Kernel $kernel): void
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

        Assert::assertEquals([new PriceChange(100, 0)], $queryBus->sendWithRouting('product.getPriceChange', $productId), 'Price change should equal to 0 after registration');

        $commandBus->send(new ChangePrice($productId, 120));

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