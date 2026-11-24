<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\ExtensionObject\CoroutineMessageChannelBuilder;
use Ecotone\Api\Lite\Test\FlowTestSupport;
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
}
