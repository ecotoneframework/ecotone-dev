<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanKind;

/**
 * licence Apache-2.0
 */
final class ProjectionFlowScenarios
{
    /**
     * @return list<FlowScenario>
     */
    public static function all(): array
    {
        return [
            self::eventDrivenProjection(),
            self::asynchronousProjection(),
        ];
    }

    private static function eventDrivenProjection(): FlowScenario
    {
        $projection = new #[Projection('orders'), FromStream('orders_stream')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
            }
        };

        return new FlowScenario('event-driven projection', [$projection::class], [$projection], [], [
            FlowStage::when(function (FlowTestSupport $ecotone, array $metadata): void {
                $ecotone->withEvents([Event::createWithType('order.placed', ['orderId' => '1'])]);
                $ecotone->publishEventWithRouting('order.placed', [], metadata: $metadata);
            })
                ->expect(
                    ExpectedSpan::named('send event')->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'order.placed'])->containing(
                        ExpectedSpan::named('route orders')->ofKind(SpanKind::Producer)->withAttributes([SpanAttribute::PROJECTION_NAME => 'orders'])->containing(
                            ExpectedSpan::named('process orders')->ofKind(SpanKind::Consumer)->withAttributes([SpanAttribute::PROJECTION_NAME => 'orders']),
                        ),
                    ),
                ),
        ]);
    }

    private static function asynchronousProjection(): FlowScenario
    {
        $projection = new #[Projection('orders'), FromStream('orders_stream'), Asynchronous('projections')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
            }
        };

        return new FlowScenario('asynchronous projection', [$projection::class], [$projection], [SimpleMessageChannelBuilder::createQueueChannel('projections')], [
            FlowStage::when(function (FlowTestSupport $ecotone, array $metadata): void {
                $ecotone->withEvents([Event::createWithType('order.placed', ['orderId' => '1'])]);
                $ecotone->publishEventWithRouting('order.placed', [], metadata: $metadata);
            })
                ->expect(
                    ExpectedSpan::named('send event')->containing(
                        ExpectedSpan::named('route orders')->ofKind(SpanKind::Producer)->containing(
                            ExpectedSpan::named('send projections')->ofKind(SpanKind::Producer),
                        ),
                    ),
                )
                ->expectNoSpanNamed('process orders'),
            FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->run('projections', ExecutionPollingMetadata::createWithTestingSetup()))
                ->expect(
                    ExpectedSpan::named('receive projections')->ofKind(SpanKind::Consumer)->containing(
                        ExpectedSpan::named('process orders')->ofKind(SpanKind::Consumer)->withAttributes([SpanAttribute::PROJECTION_NAME => 'orders']),
                    ),
                ),
        ]);
    }
}
