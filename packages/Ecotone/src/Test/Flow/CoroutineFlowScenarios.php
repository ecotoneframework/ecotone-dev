<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\ExtensionObject\CoroutineMessageChannelBuilder;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\CollectedMessages;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionRebuild;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Test\CoroutineTesting;

/**
 * licence Apache-2.0
 */
final class CoroutineFlowScenarios
{
    /**
     * @return list<FlowScenario>
     */
    public static function all(): array
    {
        return [
            self::asynchronousHandlerOnCoroutineMessageChannelRunsInTheSendingProcess(),
            self::splitterOnHandlerFansOutToHandlersOnCoroutineMessageChannel(),
            self::splitterOnHandlerIsWorkedAndGatheredOnCoroutineMessageChannels(),
            self::commandsAreCollectedIntoOneBatchOnCoroutineMessageChannel(),
            self::projectionRebuiltOnCoroutineMessageChannel(),
        ];
    }

    private static function asynchronousHandlerOnCoroutineMessageChannelRunsInTheSendingProcess(): FlowScenario
    {
        $notifier = new class () {
            #[Asynchronous('notifications')]
            #[CommandHandler('order.notify', endpointId: 'order.notifying')]
            public function notify(string $orderId): void
            {
            }
        };

        return (new FlowScenario(
            name: 'asynchronous handler on a coroutine message channel runs in the sending process',
            classesToResolve: [$notifier::class],
            services: [$notifier],
            extensionObjects: [CoroutineMessageChannelBuilder::create('notifications')],
            stages: [
                FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => CoroutineTesting::runAtOnce(fn () => $ecotone->sendCommandWithRouting('order.notify', '1', metadata: $metadata)))
                    ->expect(
                        ExpectedSpan::named('send command')->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'order.notify'])->containing(
                            ExpectedSpan::named('send notifications')->ofKind(SpanKind::Producer)->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'notifications'])->endingAfterTheTransaction()->containing(
                                ExpectedSpan::named('receive notifications')->ofKind(SpanKind::Consumer)->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'notifications'])->containing(
                                    ExpectedSpan::named('process class@anonymous::notify')->withAttributes([SpanAttribute::ENDPOINT_ID => 'order.notifying']),
                                ),
                            ),
                        ),
                    )
                    ->withNoOtherSpans(),
            ],
        ))->needingACoroutineRuntime();
    }

    private static function splitterOnHandlerFansOutToHandlersOnCoroutineMessageChannel(): FlowScenario
    {
        $imports = new class () {
            #[CommandHandler('batch.import', endpointId: 'batch.importing', outputChannelName: 'item.handle')]
            #[Splitter]
            public function import(array $items): array
            {
                return $items;
            }

            #[Asynchronous('items')]
            #[InternalHandler('item.handle', endpointId: 'item.handling')]
            public function handle(string $item): void
            {
            }
        };

        return (new FlowScenario(
            name: 'splitter on a command handler fans its result out into handlers on a coroutine message channel',
            classesToResolve: [$imports::class],
            services: [$imports],
            extensionObjects: [CoroutineMessageChannelBuilder::create('items')],
            stages: [
                FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => CoroutineTesting::runAtOnce(fn () => $ecotone->sendCommandWithRouting('batch.import', ['a', 'b'], metadata: $metadata)))
                    ->expect(
                        ExpectedSpan::named('send command')->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'batch.import'])->containing(
                            ExpectedSpan::named('process class@anonymous::import')->withAttributes([SpanAttribute::ENDPOINT_ID => 'batch.importing']),
                            ExpectedSpan::named('send items')->ofKind(SpanKind::Producer)->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'items'])->endingAfterTheTransaction()->containing(
                                ExpectedSpan::named('receive items')->ofKind(SpanKind::Consumer)->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'items'])->containing(
                                    ExpectedSpan::named('process class@anonymous::handle')->withAttributes([SpanAttribute::ENDPOINT_ID => 'item.handling']),
                                ),
                            ),
                            ExpectedSpan::named('send items')->ofKind(SpanKind::Producer)->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'items'])->endingAfterTheTransaction()->containing(
                                ExpectedSpan::named('receive items')->ofKind(SpanKind::Consumer)->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'items'])->containing(
                                    ExpectedSpan::named('process class@anonymous::handle')->withAttributes([SpanAttribute::ENDPOINT_ID => 'item.handling']),
                                ),
                            ),
                        ),
                    )
                    ->withNoOtherSpans(),
            ],
        ))->needingACoroutineRuntime();
    }

    private static function splitterOnHandlerIsWorkedAndGatheredOnCoroutineMessageChannels(): FlowScenario
    {
        $imports = new class () {
            #[CommandHandler('batch.import', endpointId: 'batch.importing', outputChannelName: 'item.handle')]
            #[Splitter(gatherChannelName: 'batch.summarize')]
            public function import(array $items): array
            {
                return $items;
            }

            #[Asynchronous('items')]
            #[InternalHandler('item.handle', endpointId: 'item.handling')]
            public function handle(string $item): string
            {
                return $item;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('batch.summarize', endpointId: 'batch.summarizing')]
            public function summarize(GatheredParts $parts): void
            {
            }
        };

        return (new FlowScenario(
            name: 'splitter on a command handler is worked and gathered on coroutine message channels',
            classesToResolve: [$imports::class],
            services: [$imports],
            extensionObjects: [CoroutineMessageChannelBuilder::create('items'), CoroutineMessageChannelBuilder::create('summaries')],
            stages: [
                FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => CoroutineTesting::runAtOnce(fn () => $ecotone->sendCommandWithRouting('batch.import', ['a'], metadata: $metadata)))
                    ->expect(
                        ExpectedSpan::named('send command')->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'batch.import'])->containing(
                            ExpectedSpan::named('process class@anonymous::import')->withAttributes([SpanAttribute::ENDPOINT_ID => 'batch.importing']),
                            ExpectedSpan::named('send items')->ofKind(SpanKind::Producer)->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'items'])->endingAfterTheTransaction()->containing(
                                ExpectedSpan::named('receive items')->ofKind(SpanKind::Consumer)->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'items'])->containing(
                                    ExpectedSpan::named('process class@anonymous::handle')->withAttributes([SpanAttribute::ENDPOINT_ID => 'item.handling']),
                                    ExpectedSpan::named('send summaries')->ofKind(SpanKind::Producer)->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'summaries'])->endingAfterTheTransaction()->containing(
                                        ExpectedSpan::named('receive summaries')->ofKind(SpanKind::Consumer)->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'summaries'])->containing(
                                            ExpectedSpan::named('process class@anonymous::summarize')->withAttributes([SpanAttribute::ENDPOINT_ID => 'batch.summarizing']),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    )
                    ->withNoOtherSpans(),
            ],
        ))->needingACoroutineRuntime();
    }

    private static function commandsAreCollectedIntoOneBatchOnCoroutineMessageChannel(): FlowScenario
    {
        $search = new class () {
            #[Collected(maxSize: 2)]
            #[Asynchronous('search')]
            #[CommandHandler('product.index', endpointId: 'product.indexing')]
            public function index(CollectedMessages $changes): void
            {
            }
        };

        return (new FlowScenario(
            name: 'commands are collected into one batch on a coroutine message channel',
            classesToResolve: [$search::class],
            services: [$search],
            extensionObjects: [CoroutineMessageChannelBuilder::create('search')],
            stages: [
                FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => CoroutineTesting::runAtOnce(fn () => $ecotone->sendCommandWithRouting('product.index', '1', metadata: $metadata)))
                    ->expect(
                        ExpectedSpan::named('send command')->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'product.index'])->containing(
                            ExpectedSpan::named('send search')->ofKind(SpanKind::Producer)->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'search'])->endingAfterTheTransaction()->containing(
                                ExpectedSpan::named('receive search')->ofKind(SpanKind::Consumer)->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'search']),
                            ),
                        ),
                    )
                    ->withNoOtherSpans(),
                FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => CoroutineTesting::runAtOnce(fn () => $ecotone->sendCommandWithRouting('product.index', '2', metadata: $metadata)))
                    ->expect(
                        ExpectedSpan::named('send command')->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'product.index'])->containing(
                            ExpectedSpan::named('send search')->ofKind(SpanKind::Producer)->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'search'])->endingAfterTheTransaction()->containing(
                                ExpectedSpan::named('receive search')->ofKind(SpanKind::Consumer)->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'search'])->containing(
                                    ExpectedSpan::named('process class@anonymous::index')->withAttributes([SpanAttribute::ENDPOINT_ID => 'product.indexing']),
                                ),
                            ),
                        ),
                    )
                    ->withNoOtherSpans(),
            ],
        ))->needingACoroutineRuntime();
    }

    private static function projectionRebuiltOnCoroutineMessageChannel(): FlowScenario
    {
        $projection = new #[Projection('orders'), FromStream('orders_stream'), ProjectionRebuild(asyncChannelName: 'rebuild')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
            }
        };

        return (new FlowScenario(
            name: 'projection rebuilt on a coroutine message channel',
            classesToResolve: [$projection::class],
            services: [$projection],
            extensionObjects: [CoroutineMessageChannelBuilder::create('rebuild')],
            stages: [
                FlowStage::when(function (FlowTestSupport $ecotone): void {
                    $ecotone->withEventStream('orders_stream', [Event::createWithType('order.placed', ['orderId' => '1'])]);
                    CoroutineTesting::runAtOnce(fn () => $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'orders']));
                })
                    ->expect(
                        ExpectedSpan::named('rebuild orders')->asRoot()->ofKind(SpanKind::Server)->containing(
                            ExpectedSpan::named('send rebuild')->ofKind(SpanKind::Producer)->endingAfterTheTransaction()->containing(
                                ExpectedSpan::named('receive rebuild')->ofKind(SpanKind::Consumer)->containing(
                                    ExpectedSpan::named('process partition batch orders')->ofKind(SpanKind::Internal)->withAttributes([SpanAttribute::PROJECTION_NAME => 'orders'])->containing(
                                        ExpectedSpan::named('process class@anonymous::whenPlaced'),
                                    ),
                                    ExpectedSpan::named('send rebuild')->ofKind(SpanKind::Producer)->endingAfterTheTransaction()->containing(
                                        ExpectedSpan::named('receive rebuild')->ofKind(SpanKind::Consumer),
                                    ),
                                ),
                            ),
                        ),
                    )
                    ->withNoOtherSpans(),
            ],
        ))->needingACoroutineRuntime();
    }
}
