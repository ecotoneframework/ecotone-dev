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
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Basket\Basket;
use Test\Ecotone\EventSourcing\Fixture\Basket\BasketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\Basket\Event\BasketWasCreated;
use Test\Ecotone\EventSourcing\Fixture\Basket\Event\ProductWasAddedToBasket;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class DecisionModelSnapshotFoldShapeAcrossDeploymentsTest extends EventSourcingMessagingTestCase
{
    public function test_a_snapshot_taken_while_the_model_folded_other_events_is_rebuilt_from_its_events(): void
    {
        $this->deployVersionThatDoesNotFoldBasketCreation();

        $observer = new class () {
            public array $observedHistories = [];

            #[CommandHandler]
            public function observe(ObserveBasketHistory $command, BasketHistoryAcrossDeployments $basketHistory): array
            {
                $this->observedHistories[] = $basketHistory->history();

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

        $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [Basket::class, BasketEventConverter::class, BasketHistoryAcrossDeployments::class, $observer::class, $snapshotConverter::class],
            containerOrAvailableServices: [
                new BasketEventConverter(),
                $observer,
                $snapshotConverter,
                DbalConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDocumentStore(),
                    DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withSnapshotsFor(BasketHistoryAcrossDeployments::class, 1),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->sendCommand(new ObserveBasketHistory('1'));

        $this->assertSame([['created', 'milk']], $observer->observedHistories);
    }

    private function deployVersionThatDoesNotFoldBasketCreation(): void
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../SnapshotFoldShape/earlier-deployment-of-a-decision-model.php', PHPUNIT_COMPOSER_INSTALL],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        $this->assertSame(0, proc_close($process), $output);
    }
}

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
    public function created(BasketWasCreated $event): void
    {
        $this->history[] = 'created';
    }

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
