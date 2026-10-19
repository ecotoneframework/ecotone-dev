<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Fixture\Flow;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Partitioned;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionRebuild;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Test\Flow\ExpectedSpan;
use Ecotone\Test\Flow\FlowScenario;
use Ecotone\Test\Flow\FlowStage;
use Ecotone\Test\Flow\Fixture\FlowOrder;
use Ecotone\Test\Flow\Fixture\FlowOrderPlaced;

/**
 * licence Apache-2.0
 */
final class EventSourcingFlowScenarios
{
    /**
     * @return list<FlowScenario>
     */
    public static function all(): array
    {
        return [
            self::projectionOnTheDatabaseEventStoreRebuilt(),
            self::partitionedProjectionRebuiltThroughADatabaseChannel(),
            self::projectionDeletedInitializedAndBackfilledFromTheDatabaseEventStore(),
        ];
    }

    private static function projectionOnTheDatabaseEventStoreRebuilt(): FlowScenario
    {
        $projection = new #[Projection('order_list'), FromAggregateStream(FlowOrder::class)] class () {
            #[EventHandler(endpointId: 'order_list.placed')]
            public function whenPlaced(FlowOrderPlaced $event): void
            {
            }
        };

        return new FlowScenario('projection on the database event store rebuilt from the console', [FlowOrder::class, FlowOrderPlaced::class, $projection::class], [$projection], [], [
            self::orderPlaced(),
            FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'order_list']))
                ->expect(self::operation('rebuild')->containing(self::projectedOrder())),
        ]);
    }

    private static function partitionedProjectionRebuiltThroughADatabaseChannel(): FlowScenario
    {
        $projection = new #[Projection('order_list'), Partitioned, FromAggregateStream(FlowOrder::class), ProjectionRebuild(asyncChannelName: 'order_list_rebuild')] class () {
            #[EventHandler(endpointId: 'order_list.placed')]
            public function whenPlaced(FlowOrderPlaced $event): void
            {
            }
        };

        return new FlowScenario('partitioned projection rebuilt through a database channel', [FlowOrder::class, FlowOrderPlaced::class, $projection::class], [$projection], [DbalBackedMessageChannelBuilder::create('order_list_rebuild')], [
            self::orderPlaced(),
            FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'order_list']))
                ->expect(self::operation('rebuild')->containing(ExpectedSpan::named('send order_list_rebuild')->ofKind(SpanKind::Producer)))
                ->expectNoSpanNamed('receive order_list_rebuild')
                ->leavingWorkPending(),
            FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->run('order_list_rebuild', ExecutionPollingMetadata::createWithTestingSetup()))
                ->expect(
                    self::operation('rebuild')->containing(
                        ExpectedSpan::named('send order_list_rebuild')->containing(
                            ExpectedSpan::named('receive order_list_rebuild')->ofKind(SpanKind::Consumer)->containing(self::projectedOrder()),
                        ),
                    ),
                ),
        ]);
    }

    private static function projectionDeletedInitializedAndBackfilledFromTheDatabaseEventStore(): FlowScenario
    {
        $projection = new #[Projection('order_list'), FromAggregateStream(FlowOrder::class)] class () {
            #[EventHandler(endpointId: 'order_list.placed')]
            public function whenPlaced(FlowOrderPlaced $event): void
            {
            }
        };

        return new FlowScenario('projection deleted, initialized and backfilled from the database event store', [FlowOrder::class, FlowOrderPlaced::class, $projection::class], [$projection], [], [
            self::orderPlaced(),
            FlowStage::when(function (FlowTestSupport $ecotone): void {
                $ecotone->deleteProjection('order_list');
                $ecotone->initializeProjection('order_list');
                $ecotone->runConsoleCommand('ecotone:projection:backfill', ['name' => 'order_list']);
            })
                ->expect(self::operation('delete'), self::operation('initialize'), self::operation('backfill')->containing(self::projectedOrder())),
        ]);
    }

    private static function orderPlaced(): FlowStage
    {
        return FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => $ecotone->sendCommandWithRouting('flow_order.place', ['orderId' => 'order-1'], metadata: $metadata))
            ->expect(
                ExpectedSpan::named('send command')->containing(
                    ExpectedSpan::named('process FlowOrder::place')->withAttributes([SpanAttribute::ENDPOINT_ID => 'flow_order.placing']),
                    ExpectedSpan::named('send event')->containing(
                        ExpectedSpan::named('route order_list')->containing(
                            ExpectedSpan::named('process order_list')->containing(self::projectedOrder()),
                        ),
                    ),
                ),
            );
    }

    private static function operation(string $operation): ExpectedSpan
    {
        return ExpectedSpan::named($operation . ' order_list')->asRoot()->ofKind(SpanKind::Server)->withAttributes([SpanAttribute::PROJECTION_NAME => 'order_list', SpanAttribute::PROJECTION_OPERATION => $operation]);
    }

    private static function projectedOrder(): ExpectedSpan
    {
        return ExpectedSpan::named('process class@anonymous::whenPlaced')->withAttributes([SpanAttribute::ENDPOINT_ID => 'order_list.placed']);
    }
}
