<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter as PayloadConverter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\MediaTypeConverter;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Basket\Basket;
use Test\Ecotone\EventSourcing\Fixture\Basket\BasketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\AddProduct;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\CreateBasket;
use Test\Ecotone\EventSourcing\Fixture\Basket\Event\ProductWasAddedToBasket;

require $argv[1];

final readonly class ObserveBasketHistory
{
    public function __construct(public string $id)
    {
    }
}

final readonly class BasketHistoryObserved
{
    public function __construct(public string $basketId)
    {
    }
}

#[DecisionModel(aggregate: Basket::class)]
final class BasketHistoryAcrossDeployments
{
    private array $history = [];

    #[EventSourcingHandler]
    public function productAdded(ProductWasAddedToBasket $event): void
    {
        $this->history[] = $event->getProductName();
    }

    public function history(): array
    {
        return $this->history;
    }

    public function toArray(): array
    {
        return ['history' => $this->history];
    }

    public static function fromArray(array $state): self
    {
        $basketHistory = new self();
        $basketHistory->history = $state['history'];

        return $basketHistory;
    }
}

$observer = new class () {
    #[CommandHandler]
    public function observe(ObserveBasketHistory $command, BasketHistoryAcrossDeployments $basketHistory): array
    {
        return [new BasketHistoryObserved($command->id)];
    }

    #[PayloadConverter]
    public function fromObserved(BasketHistoryObserved $event): array
    {
        return ['basketId' => $event->basketId];
    }

    #[PayloadConverter]
    public function toObserved(array $event): BasketHistoryObserved
    {
        return new BasketHistoryObserved($event['basketId']);
    }
};

$snapshotConverter = new #[MediaTypeConverter] class () implements Converter {
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        return $targetMediaType->isCompatibleWith(MediaType::createApplicationJson())
            ? json_encode($source->toArray())
            : BasketHistoryAcrossDeployments::fromArray(json_decode($source, true));
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $sourceType->getTypeHint() === BasketHistoryAcrossDeployments::class || $targetType->getTypeHint() === BasketHistoryAcrossDeployments::class;
    }
};

EcotoneLite::bootstrapFlowTesting(
    classesToResolve: [Basket::class, BasketEventConverter::class, BasketHistoryAcrossDeployments::class, $observer::class, $snapshotConverter::class],
    containerOrAvailableServices: [
        new BasketEventConverter(),
        $observer,
        $snapshotConverter,
        DbalConnectionFactory::class => EventSourcingMessagingTestCase::getConnectionFactory(),
    ],
    configuration: ServiceConfiguration::createWithDefaults()
        ->withEnvironment('prod')
        ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
        ->withExtensionObjects([
            DbalConfiguration::createWithDefaults()->withDocumentStore(),
            DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withSnapshotsFor(BasketHistoryAcrossDeployments::class, 1),
        ]),
    pathToRootCatalog: __DIR__ . '/../../../',
    licenceKey: LicenceTesting::VALID_LICENCE,
)
    ->setupDatabaseTables()
    ->sendCommand(new CreateBasket('1'))
    ->sendCommand(new AddProduct('1', 'milk'))
    ->sendCommand(new ObserveBasketHistory('1'));
