<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Repository;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Saga;
use Ecotone\Api\Attribute\Version;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use PHPUnit\Framework\TestCase;
use Tempest\Database\IsDatabaseModel;

/**
 * licence Apache-2.0
 * @internal
 */
final class VersionedTempestModelTest extends TestCase
{
    public function test_a_versioned_aggregate_stored_as_a_tempest_model_is_refused_at_bootstrap(): void
    {
        $aggregate = new #[Aggregate] class () {
            use IsDatabaseModel;

            #[Identifier]
            public string $basketId;

            #[Version]
            public int $version = 0;

            #[CommandHandler('basket.open')]
            public static function open(string $basketId): self
            {
                $basket = new self();
                $basket->basketId = $basketId;

                return $basket;
            }
        };

        $this->assertRefused(
            $aggregate,
            'Aggregate ' . $aggregate::class . ' is stored as a Tempest model (it uses ' . IsDatabaseModel::class . ') and declares #[Version] on $version, so its saves would be version-checked.',
        );
    }

    public function test_a_versioned_saga_stored_as_a_tempest_model_is_refused_at_bootstrap(): void
    {
        $saga = new #[Saga] class () {
            use IsDatabaseModel;

            #[Identifier]
            public string $orderId;

            #[Version]
            public int $revision = 0;

            #[CommandHandler('order.track')]
            public static function track(string $orderId): self
            {
                $saga = new self();
                $saga->orderId = $orderId;

                return $saga;
            }
        };

        $this->assertRefused(
            $saga,
            'Saga ' . $saga::class . ' is stored as a Tempest model (it uses ' . IsDatabaseModel::class . ') and declares #[Version] on $revision, so its saves would be version-checked.',
        );
    }

    public function test_an_aggregate_stored_as_a_tempest_model_without_a_version_boots(): void
    {
        $aggregate = new #[Aggregate] class () {
            use IsDatabaseModel;

            #[Identifier]
            public string $basketId;

            #[CommandHandler('basket.open')]
            public static function open(string $basketId): self
            {
                $basket = new self();
                $basket->basketId = $basketId;

                return $basket;
            }
        };

        $ecotone = $this->bootstrap($aggregate::class);

        $ecotone->sendCommandWithRouting('basket.open', 'b-1');

        $this->assertSame('b-1', $ecotone->getAggregate($aggregate::class, 'b-1')->basketId);
    }

    private function assertRefused(object $fixture, string $what): void
    {
        try {
            $this->bootstrap($fixture::class);
            $this->fail('A versioned Tempest model was not refused at bootstrap');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString($what, $exception->getMessage());
            $this->assertStringContainsString("Tempest's database API does not report whether an UPDATE matched a row", $exception->getMessage());
            $this->assertStringContainsString('remove #[Version]', $exception->getMessage());
            $this->assertStringContainsString('DbalConfiguration::withDocumentStore(enableDocumentStoreStateStoredRepository: true)', $exception->getMessage());
            $this->assertStringContainsString('#[EventSourcingAggregate]', $exception->getMessage());
        }
    }

    private function bootstrap(string $fixtureClass): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [$fixtureClass],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::TEMPEST_PACKAGE]),
        );
    }
}
