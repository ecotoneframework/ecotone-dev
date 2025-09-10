<?php

namespace Monorepo\Benchmark;

use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\ProjectionManager;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\ConfiguredMessagingSystemWithTestSupport;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Projecting\ProjectionRegistry;
use Enqueue\Dbal\DbalConnectionFactory;
use Monorepo\ExampleAppEventSourcing\Common\Command\ChangePrice;
use Monorepo\ExampleAppEventSourcing\Common\Command\RegisterProduct;
use Monorepo\ExampleAppEventSourcing\Common\PriceChange;
use Monorepo\ExampleAppEventSourcing\Common\Product;
use Monorepo\ExampleAppEventSourcing\EcotoneProjection\PriceChangeOverTimeProjectionWithEcotoneProjection;
use Monorepo\ExampleAppEventSourcing\ExampleAppEventSourcingCaseTrait;
use Monorepo\ExampleAppEventSourcing\ProophProjection\PriceChangeOverTimeProjection;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;

#[Warmup(0), Revs(10), Iterations(5)]
class ProjectingBenchmark
{
    use ExampleAppEventSourcingCaseTrait;


    private static ConfiguredMessagingSystem $prooph;
    private static ConfiguredMessagingSystem $ecotone;
    private static array $expectedProductIds = [];

    public static function bootEcotone(string $name, array $container, array $namespaces): ConfiguredMessagingSystem
    {
        $connectionString = getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
        return EcotoneLite::bootstrap(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => new DbalConnectionFactory($connectionString),
                ...$container,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->doNotLoadCatalog()
                ->withNamespaces(['Monorepo\\ExampleAppEventSourcing\\Common\\', ...$namespaces])
                ->withCacheDirectoryPath(self::getProjectDir() . "/var/cache/$name")
                ->withDefaultErrorChannel('errorChannel')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::JMS_CONVERTER_PACKAGE
                ])),
            useCachedVersion: true,
            pathToRootCatalog: self::getProjectDir(),
        );
    }

    public function setUp(): void
    {
        self::$prooph = self::bootEcotone(
            name: 'prooph',
            container: [PriceChangeOverTimeProjection::class => new PriceChangeOverTimeProjection()],
            namespaces: ['Monorepo\\ExampleAppEventSourcing\\ProophProjection\\']
        );

        self::$ecotone = self::bootEcotone(
            name: 'ecotone',
            container: [PriceChangeOverTimeProjectionWithEcotoneProjection::class => new PriceChangeOverTimeProjectionWithEcotoneProjection()],
            namespaces: ['Monorepo\\ExampleAppEventSourcing\\EcotoneProjection\\']
        );

        $eventStore = self::$prooph->getGatewayByName(EventStore::class);
        $eventStore->delete(Product::class);
        self::deleteEcotoneProjection();
        self::deleteProophProjection();
    }

    #[BeforeMethods("setUp")]
    public function bench_ecotone_projection(): void
    {
        self::execute(self::$ecotone);
    }

    #[BeforeMethods("setUp")]
    public function bench_prooph_projection(): void
    {
        self::execute(self::$prooph);
    }

    #[BeforeMethods("setUp")]
    public function bench_ecotone_projection_with_deletion(): void
    {
        self::executeWithDeletion(self::$ecotone, self::deleteEcotoneProjection(...));
    }

    #[BeforeMethods("setUp")]
    public function bench_prooph_projection_with_deletion(): void
    {
        self::executeWithDeletion(self::$prooph, self::deleteProophProjection(...));
    }

    public static function deleteEcotoneProjection(): void
    {
        $registry = self::$ecotone->getServiceFromContainer(ProjectionRegistry::class);
        $registry->get(PriceChangeOverTimeProjectionWithEcotoneProjection::NAME)->delete();
    }

    public static function deleteProophProjection(): void
    {
        $projectionManager = self::$prooph->getServiceFromContainer(ProjectionManager::class);
        $projectionManager->deleteProjection(PriceChangeOverTimeProjection::NAME);
    }

    public static function execute(ConfiguredMessagingSystem $messagingSystem): void
    {
        $commandBus = $messagingSystem->getCommandBus();
        $queryBus = $messagingSystem->getQueryBus();

        $productId = Uuid::uuid4()->toString();
        $commandBus->send(new RegisterProduct($productId, 100));

        Assert::assertEquals([new PriceChange(100, 0)], $queryBus->sendWithRouting('product.getPriceChange', $productId), 'Price change should equal to 0 after registration');

        $commandBus->send(new ChangePrice($productId, 120));

        Assert::assertEquals([new PriceChange(100, 0), new PriceChange(120, 20)], $queryBus->sendWithRouting('product.getPriceChange', $productId), 'Price change should equal to 0 after registration');
    }

    private static function executeWithDeletion(ConfiguredMessagingSystem $messagingSystem, \Closure $deleteProjection): void
    {
        $commandBus = $messagingSystem->getCommandBus();
        $queryBus = $messagingSystem->getQueryBus();

        $productId = Uuid::uuid4()->toString();
        $commandBus->send(new RegisterProduct($productId, 100));
        $commandBus->send(new ChangePrice($productId, 120));

        $deleteProjection();

        Assert::assertEquals([], $queryBus->sendWithRouting('product.getPriceChange', $productId), 'Price change should equal to 0 after deletion');

        $commandBus->send(new ChangePrice($productId, 130));

        Assert::assertEquals([
            new PriceChange(100, 0),
            new PriceChange(120, 20),
            new PriceChange(130, 10)
        ], $queryBus->sendWithRouting('product.getPriceChange', $productId), 'Price changes should be projected again after deletion');
    }

    public function fillEcotone(): void
    {
        $projection = self::$ecotone->getServiceFromContainer(ProjectionRegistry::class)->get(PriceChangeOverTimeProjectionWithEcotoneProjection::NAME);
        $commandBus = self::$ecotone->getCommandBus();
        $projection->delete();

        self::$expectedProductIds = [];
        for ($i = 0; $i < 100; $i++) {
            self::$expectedProductIds[] = $productId = Uuid::uuid4()->toString();
            $commandBus->send(new RegisterProduct($productId, 100));
            $commandBus->send(new ChangePrice($productId, 120));
            $commandBus->send(new ChangePrice($productId, 130));
        }

        $projection->delete();
    }

    public function fillProoph(): void
    {
        $projection = self::$prooph->getServiceFromContainer(ProjectionManager::class);
        $commandBus = self::$prooph->getCommandBus();
        $projection->deleteProjection(PriceChangeOverTimeProjectionWithEcotoneProjection::NAME);

        self::$expectedProductIds = [];
        for ($i = 0; $i < 100; $i++) {
            self::$expectedProductIds[] = $productId = Uuid::uuid4()->toString();
            $commandBus->send(new RegisterProduct($productId, 100));
            $commandBus->send(new ChangePrice($productId, 120));
            $commandBus->send(new ChangePrice($productId, 130));
        }

        $projection->deleteProjection(PriceChangeOverTimeProjectionWithEcotoneProjection::NAME);
    }

    #[BeforeMethods(["setUp", "fillEcotone"])]
    #[Revs(1), Warmup(0)]
    public function bench_ecotone_projection_backfill(): void
    {
        Assert::assertEquals([],
            self::$ecotone->getQueryBus()->sendWithRouting('product.getPriceChange', self::$expectedProductIds[0])
        );
        $projectionManager = self::$ecotone->getServiceFromContainer(ProjectionRegistry::class)->get(PriceChangeOverTimeProjectionWithEcotoneProjection::NAME);
        $projectionManager->backfill();
        Assert::assertEquals([
            new PriceChange(100, 0),
            new PriceChange(120, 20),
            new PriceChange(130, 10),
        ],
            self::$ecotone->getQueryBus()->sendWithRouting('product.getPriceChange', self::$expectedProductIds[0])
        );
    }

    #[BeforeMethods(["setUp", "fillProoph"])]
    #[Revs(1), Warmup(0)]
    public function bench_prooph_projection_backfill(): void
    {
        Assert::assertEquals([],
            self::$prooph->getQueryBus()->sendWithRouting('product.getPriceChange', self::$expectedProductIds[0])
        );
        $projectionManager = self::$prooph->getServiceFromContainer(ProjectionManager::class);
        $projectionManager->triggerProjection(PriceChangeOverTimeProjection::NAME);
        Assert::assertEquals([
            new PriceChange(100, 0),
            new PriceChange(120, 20),
            new PriceChange(130, 10),
        ],
            self::$prooph->getQueryBus()->sendWithRouting('product.getPriceChange', self::$expectedProductIds[0])
        );
    }
}