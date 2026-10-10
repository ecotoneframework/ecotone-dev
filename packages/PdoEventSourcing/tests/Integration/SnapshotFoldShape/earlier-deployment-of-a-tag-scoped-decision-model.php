<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter as PayloadConverter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\MediaTypeConverter;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

require $argv[1];

final readonly class SpendFromWalletAcrossDeployments
{
    public function __construct(public string $walletId, public int $amount)
    {
    }
}

final readonly class RefundToWalletAcrossDeployments
{
    public function __construct(public string $walletId, public int $amount)
    {
    }
}

final readonly class WalletSpentAcrossDeployments
{
    public function __construct(#[EventTag('wallet')] public string $walletId, public int $amount)
    {
    }
}

final readonly class WalletRefundedAcrossDeployments
{
    public function __construct(#[EventTag('wallet')] public string $walletId, public int $amount)
    {
    }
}

#[DecisionModel]
final class SpentTodayAcrossDeployments
{
    private int $spent = 0;

    #[EventSourcingHandler]
    public function whenSpent(WalletSpentAcrossDeployments $event): void
    {
        $this->spent += $event->amount;
    }

    public function spent(): int
    {
        return $this->spent;
    }

    public function toArray(): array
    {
        return ['spent' => $this->spent];
    }

    public static function fromArray(array $state): self
    {
        $spentToday = new self();
        $spentToday->spent = $state['spent'];

        return $spentToday;
    }
}

$wallet = new class () {
    #[CommandHandler]
    public function spend(SpendFromWalletAcrossDeployments $command, SpentTodayAcrossDeployments $spentToday): array
    {
        return [new WalletSpentAcrossDeployments($command->walletId, $command->amount)];
    }

    #[CommandHandler]
    public function refund(RefundToWalletAcrossDeployments $command, SpentTodayAcrossDeployments $spentToday): array
    {
        return [new WalletRefundedAcrossDeployments($command->walletId, $command->amount)];
    }

    #[PayloadConverter]
    public function fromSpent(WalletSpentAcrossDeployments $event): array
    {
        return ['walletId' => $event->walletId, 'amount' => $event->amount];
    }

    #[PayloadConverter]
    public function toSpent(array $event): WalletSpentAcrossDeployments
    {
        return new WalletSpentAcrossDeployments($event['walletId'], $event['amount']);
    }

    #[PayloadConverter]
    public function fromRefunded(WalletRefundedAcrossDeployments $event): array
    {
        return ['walletId' => $event->walletId, 'amount' => $event->amount];
    }

    #[PayloadConverter]
    public function toRefunded(array $event): WalletRefundedAcrossDeployments
    {
        return new WalletRefundedAcrossDeployments($event['walletId'], $event['amount']);
    }
};

$snapshotConverter = new #[MediaTypeConverter] class () implements Converter {
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        return $targetMediaType->isCompatibleWith(MediaType::createApplicationJson())
            ? json_encode($source->toArray())
            : SpentTodayAcrossDeployments::fromArray(json_decode($source, true));
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $sourceType->getTypeHint() === SpentTodayAcrossDeployments::class || $targetType->getTypeHint() === SpentTodayAcrossDeployments::class;
    }
};

EcotoneLite::bootstrapFlowTestingWithEventStore(
    classesToResolve: [SpentTodayAcrossDeployments::class, WalletSpentAcrossDeployments::class, WalletRefundedAcrossDeployments::class, $wallet::class, $snapshotConverter::class],
    containerOrAvailableServices: [
        $wallet,
        $snapshotConverter,
        EventSourcingMessagingTestCase::getConnectionFactory(),
    ],
    configuration: ServiceConfiguration::createWithDefaults()
        ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
        ->withExtensionObjects([
            DbalConfiguration::createWithDefaults()->withDocumentStore(),
            DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withSnapshotsFor(SpentTodayAcrossDeployments::class, 1),
        ])
        ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
    pathToRootCatalog: __DIR__ . '/../../../',
    runForProductionEventStore: true,
    licenceKey: LicenceTesting::VALID_LICENCE,
)
    ->setupDatabaseTables()
    ->sendCommand(new RefundToWalletAcrossDeployments('w-1', 3))
    ->sendCommand(new SpendFromWalletAcrossDeployments('w-1', 10))
    ->sendCommand(new SpendFromWalletAcrossDeployments('w-1', 5));
