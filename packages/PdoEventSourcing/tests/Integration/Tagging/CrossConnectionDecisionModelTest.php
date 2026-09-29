<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class CrossConnectionDecisionModelTest extends TestCase
{
    public function test_model_traced_to_a_stream_on_another_connection_is_rejected_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [
                WidgetAggregateForCrossConnectionDecisionModelTest::class,
                WidgetCountForCrossConnectionDecisionModelTest::class,
                HandlerInjectingWidgetCountForCrossConnectionDecisionModelTest::class,
            ],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_aggregate_backed_model_whose_aggregate_stream_is_on_another_connection_is_rejected_at_bootstrap(): void
    {
        try {
            EcotoneLite::bootstrapFlowTesting(
                classesToResolve: [
                    ArchivedWidgetForCrossConnectionDecisionModelTest::class,
                    ArchivedWidgetCountForCrossConnectionDecisionModelTest::class,
                    HandlerInjectingArchivedWidgetCountForCrossConnectionDecisionModelTest::class,
                ],
                configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
                licenceKey: LicenceTesting::VALID_LICENCE,
            );
            $this->fail('Expected a ConfigurationException');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString(ArchivedWidgetCountForCrossConnectionDecisionModelTest::class, $exception->getMessage());
            $this->assertStringContainsString(ArchivedWidgetForCrossConnectionDecisionModelTest::class, $exception->getMessage());
            $this->assertStringContainsString('archiveConnectionForCrossConnectionDecisionModelTest', $exception->getMessage());
        }
    }

    public function test_aggregate_backed_model_on_the_handlers_connection_is_accepted_at_bootstrap(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [
                SameConnectionAggregateForCrossConnectionDecisionModelTest::class,
                SameConnectionAggregateBackedModelForCrossConnectionDecisionModelTest::class,
                HandlerInjectingSameConnectionAggregateBackedModelForCrossConnectionDecisionModelTest::class,
                SameConnectionWidgetDefinedForCrossConnectionDecisionModelTest::class,
            ],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->assertNotNull($ecotone);
    }

    public function test_model_traced_to_the_same_connection_as_the_handler_is_accepted_at_bootstrap(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [
                SameConnectionAggregateForCrossConnectionDecisionModelTest::class,
                SameConnectionModelForCrossConnectionDecisionModelTest::class,
                SameConnectionWidgetDefinedForCrossConnectionDecisionModelTest::class,
            ],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->assertNotNull($ecotone);
    }
}

final readonly class WidgetDefinedForCrossConnectionDecisionModelTest
{
    public function __construct(
        #[EventTag('widget')] public string $widgetId,
    ) {
    }
}

#[EventSourcingAggregate]
#[Stream('cross_connection_widget_stream', connectionReferenceName: 'secondaryConnectionForCrossConnectionDecisionModelTest')]
final class WidgetAggregateForCrossConnectionDecisionModelTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $widgetId;

    #[CommandHandler]
    public static function define(DefineWidgetForCrossConnectionDecisionModelTest $command): array
    {
        return [new WidgetDefinedForCrossConnectionDecisionModelTest($command->widgetId)];
    }

    #[EventSourcingHandler]
    public function applyDefined(WidgetDefinedForCrossConnectionDecisionModelTest $event): void
    {
        $this->widgetId = $event->widgetId;
    }
}

final readonly class DefineWidgetForCrossConnectionDecisionModelTest
{
    public function __construct(
        public string $widgetId,
    ) {
    }
}

#[DecisionModel]
final class WidgetCountForCrossConnectionDecisionModelTest
{
    private int $count = 0;

    #[EventSourcingHandler]
    public function whenDefined(WidgetDefinedForCrossConnectionDecisionModelTest $event): void
    {
        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }
}

final readonly class CheckWidgetCountForCrossConnectionDecisionModelTest
{
    public function __construct(
        public string $widgetId,
    ) {
    }
}

final class HandlerInjectingWidgetCountForCrossConnectionDecisionModelTest
{
    #[CommandHandler]
    public function handle(CheckWidgetCountForCrossConnectionDecisionModelTest $command, WidgetCountForCrossConnectionDecisionModelTest $widgetCount): array
    {
        return [];
    }
}

final readonly class SameConnectionWidgetDefinedForCrossConnectionDecisionModelTest
{
    public function __construct(
        #[EventTag('widget')] public string $widgetId,
    ) {
    }
}

#[EventSourcingAggregate]
#[AggregateType('SameConnectionAggregate')]
final class SameConnectionAggregateForCrossConnectionDecisionModelTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $widgetId;

    #[CommandHandler]
    public static function define(SameConnectionDefineWidgetForCrossConnectionDecisionModelTest $command, SameConnectionModelForCrossConnectionDecisionModelTest $model): array
    {
        return [new SameConnectionWidgetDefinedForCrossConnectionDecisionModelTest($command->widgetId)];
    }

    #[EventSourcingHandler]
    public function applyDefined(SameConnectionWidgetDefinedForCrossConnectionDecisionModelTest $event): void
    {
        $this->widgetId = $event->widgetId;
    }
}

final readonly class SameConnectionDefineWidgetForCrossConnectionDecisionModelTest
{
    public function __construct(
        public string $widgetId,
    ) {
    }
}

#[DecisionModel]
final class SameConnectionModelForCrossConnectionDecisionModelTest
{
    private int $count = 0;

    #[EventSourcingHandler]
    public function whenDefined(SameConnectionWidgetDefinedForCrossConnectionDecisionModelTest $event): void
    {
        $this->count++;
    }
}

final readonly class ArchivedWidgetDefinedForCrossConnectionDecisionModelTest
{
    public function __construct(
        public string $widgetId,
    ) {
    }
}

#[EventSourcingAggregate]
#[AggregateType('ArchivedWidget')]
#[Stream('archived_widget_stream', connectionReferenceName: 'archiveConnectionForCrossConnectionDecisionModelTest')]
final class ArchivedWidgetForCrossConnectionDecisionModelTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $widgetId;

    #[EventSourcingHandler]
    public function applyDefined(ArchivedWidgetDefinedForCrossConnectionDecisionModelTest $event): void
    {
        $this->widgetId = $event->widgetId;
    }
}

#[DecisionModel(aggregate: ArchivedWidgetForCrossConnectionDecisionModelTest::class)]
final class ArchivedWidgetCountForCrossConnectionDecisionModelTest
{
    private int $count = 0;

    #[EventSourcingHandler]
    public function whenDefined(ArchivedWidgetDefinedForCrossConnectionDecisionModelTest $event): void
    {
        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }
}

final class HandlerInjectingArchivedWidgetCountForCrossConnectionDecisionModelTest
{
    #[CommandHandler]
    public function handle(CheckWidgetCountForCrossConnectionDecisionModelTest $command, ArchivedWidgetCountForCrossConnectionDecisionModelTest $widgetCount): array
    {
        return [];
    }
}

#[DecisionModel(aggregate: SameConnectionAggregateForCrossConnectionDecisionModelTest::class)]
final class SameConnectionAggregateBackedModelForCrossConnectionDecisionModelTest
{
    private int $count = 0;

    #[EventSourcingHandler]
    public function whenDefined(SameConnectionWidgetDefinedForCrossConnectionDecisionModelTest $event): void
    {
        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }
}

final class HandlerInjectingSameConnectionAggregateBackedModelForCrossConnectionDecisionModelTest
{
    #[CommandHandler]
    public function handle(CheckWidgetCountForCrossConnectionDecisionModelTest $command, SameConnectionAggregateBackedModelForCrossConnectionDecisionModelTest $widgetCount): array
    {
        return [];
    }
}
