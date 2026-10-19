<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Router;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanKind;

/**
 * licence Apache-2.0
 */
final class FlowScenarioCatalogue
{
    /**
     * @return iterable<string, array{FlowScenario}>
     */
    public static function dataProvider(): iterable
    {
        foreach (self::all() as $scenario) {
            yield $scenario->name => [$scenario];
        }
    }

    /**
     * @return list<FlowScenario>
     */
    public static function all(): array
    {
        return [
            self::synchronousCommandHandler(),
            self::eventPublishedByCommandHandlerReachesAsynchronousSubscriber(),
            self::routerSendsTheCommandDownOneOfItsChannels(),
        ];
    }

    private static function synchronousCommandHandler(): FlowScenario
    {
        $orders = new class () {
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
            }
        };

        return new FlowScenario(
            'synchronous command handler',
            [$orders::class],
            [$orders],
            [],
            [
                FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => $ecotone->sendCommandWithRouting('order.place', '1', metadata: $metadata))
                    ->expect(
                        ExpectedSpan::named('send command')->ofKind(SpanKind::Server)->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'order.place'])->containing(
                            ExpectedSpan::named('process class@anonymous::place')->withAttributes([SpanAttribute::ENDPOINT_ID => 'order.placing']),
                        ),
                    ),
            ],
        );
    }

    private static function eventPublishedByCommandHandlerReachesAsynchronousSubscriber(): FlowScenario
    {
        $orders = new class () {
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', $orderId);
            }
        };
        $notifier = new class () {
            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'order.notifying')]
            public function notify(string $orderId): void
            {
            }
        };

        return new FlowScenario(
            'event published by a command handler reaches an asynchronous subscriber',
            [$orders::class, $notifier::class],
            [$orders, $notifier],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications')],
            [
                FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => $ecotone->sendCommandWithRouting('order.place', '1', metadata: $metadata))
                    ->expect(
                        ExpectedSpan::named('send command')->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'order.place'])->containing(
                            ExpectedSpan::named('process class@anonymous::place')->containing(
                                ExpectedSpan::named('send event')->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'order.placed'])->containing(
                                    ExpectedSpan::named('route order.notifying')->ofKind(SpanKind::Producer),
                                ),
                            ),
                            ExpectedSpan::named('send notifications')->ofKind(SpanKind::Producer),
                        ),
                    )
                    ->expectNoSpanNamed('process class@anonymous::notify'),
                FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->run('notifications'))
                    ->expect(
                        ExpectedSpan::named('receive notifications')->ofKind(SpanKind::Consumer)->containing(
                            ExpectedSpan::named('process class@anonymous::notify')->withAttributes([SpanAttribute::ENDPOINT_ID => 'order.notifying']),
                        ),
                    ),
            ],
        );
    }

    private static function routerSendsTheCommandDownOneOfItsChannels(): FlowScenario
    {
        $shipments = new class () {
            #[CommandHandler('shipment.dispatch', outputChannelName: 'shipment.route', endpointId: 'shipment.dispatching')]
            public function dispatch(string $shipmentId): string
            {
                return $shipmentId;
            }

            #[Router('shipment.route')]
            public function route(string $shipmentId): string
            {
                return 'shipment.express';
            }

            #[InternalHandler('shipment.express', endpointId: 'shipment.expressing')]
            public function express(string $shipmentId): void
            {
            }

            #[InternalHandler('shipment.standard', endpointId: 'shipment.standard.handling')]
            public function standard(string $shipmentId): void
            {
            }
        };

        return new FlowScenario(
            'router sends the command down one of its channels',
            [$shipments::class],
            [$shipments],
            [],
            [
                FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => $ecotone->sendCommandWithRouting('shipment.dispatch', '1', metadata: $metadata))
                    ->expect(
                        ExpectedSpan::named('send command')->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'shipment.dispatch'])->containing(
                            ExpectedSpan::named('process class@anonymous::dispatch')->withAttributes([SpanAttribute::ENDPOINT_ID => 'shipment.dispatching']),
                            ExpectedSpan::named('process class@anonymous::express')->withAttributes([SpanAttribute::ENDPOINT_ID => 'shipment.expressing']),
                        ),
                    )
                    ->expectNoSpanNamed('process class@anonymous::standard'),
            ],
        );
    }
}
