<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests;

use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\Database\EventStreamTableManager;
use Ecotone\EventSourcing\Database\ProjectionStateTableManager;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Monorepo\ExampleAppEventSourcing\Common\Command\ChangePrice;
use Monorepo\ExampleAppEventSourcing\Common\Command\RegisterProduct;
use Monorepo\ExampleAppEventSourcing\Common\PriceChange;
use Monorepo\ExampleAppEventSourcing\ExampleAppEventSourcingCaseTrait;
use Monorepo\ExampleAppEventSourcing\Symfony\Kernel;
use Monorepo\ExampleAppEventSourcing\Symfony\Kernel as SymfonyKernel;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;

final class EventSourcingStackTest extends FullAppTestCase
{
    use ExampleAppEventSourcingCaseTrait;

    public static function modulePackagesToLoad(): array
    {
        return [
            ModulePackageList::EVENT_SOURCING_PACKAGE,
            ModulePackageList::JMS_CONVERTER_PACKAGE,
        ];
    }

    /**
     * Symfony, Laravel and Lite all point at the same DATABASE_DSN, so the tables this scenario
     * needs only have to be provisioned once here rather than in each executeFor*() implementation.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $connection = (new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone'))
            ->createContext()
            ->getDbalConnection();

        foreach ([
            new EventStreamTableManager([StreamTableRegistry::DEFAULT_STREAM], true, true),
            new ProjectionStateTableManager(ProjectionStateTableManager::DEFAULT_TABLE_NAME, true, true),
        ] as $tableManager) {
            if (! $tableManager->isInitialized($connection)) {
                $tableManager->createTable($connection);
            }
        }
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


    public function executeForLite(ConfiguredMessagingSystem $messagingSystem): void
    {
        $this->executeTestScenario(
            $messagingSystem->getCommandBus(),
            $messagingSystem->getQueryBus()
        );
    }
}