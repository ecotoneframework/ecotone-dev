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
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

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
final class TagScopedDecisionModelSnapshotFoldShapeAcrossDeploymentsTest extends EventSourcingMessagingTestCase
{
    public function test_a_snapshot_taken_while_the_model_folded_other_events_is_rebuilt_from_its_events(): void
    {
        $this->deployVersionThatDoesNotFoldRefunds();

        $wallet = new class () {
            public array $observedSpent = [];

            #[CommandHandler]
            public function spend(SpendFromWalletAcrossDeployments $command, SpentTodayAcrossDeployments $spentToday): array
            {
                $this->observedSpent[] = $spentToday->spent();

                return [new WalletSpentAcrossDeployments($command->walletId, $command->amount)];
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

        $this->bootstrapFlowTesting(
            classesToResolve: [SpentTodayAcrossDeployments::class, WalletSpentAcrossDeployments::class, WalletRefundedAcrossDeployments::class, $wallet::class, $snapshotConverter::class],
            containerOrAvailableServices: [
                $wallet,
                $snapshotConverter,
                self::getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDocumentStore(),
                    DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withSnapshotsFor(SpentTodayAcrossDeployments::class, 1),
                ])
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../../',
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->sendCommand(new SpendFromWalletAcrossDeployments('w-1', 1));

        $this->assertSame([12], $wallet->observedSpent);
    }

    private function deployVersionThatDoesNotFoldRefunds(): void
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../SnapshotFoldShape/earlier-deployment-of-a-tag-scoped-decision-model.php', PHPUNIT_COMPOSER_INSTALL],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        $this->assertSame(0, proc_close($process), $output);
    }
}

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

    #[EventSourcingHandler]
    public function whenRefunded(WalletRefundedAcrossDeployments $event): void
    {
        $this->spent -= $event->amount;
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
