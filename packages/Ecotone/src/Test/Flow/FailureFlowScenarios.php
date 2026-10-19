<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanStatus;
use RuntimeException;

/**
 * licence Apache-2.0
 */
final class FailureFlowScenarios
{
    /**
     * @return list<FlowScenario>
     */
    public static function all(): array
    {
        return [
            self::synchronousHandlerFailing(),
            self::asynchronousHandlerFailingIntoTheErrorChannel(),
        ];
    }

    private static function synchronousHandlerFailing(): FlowScenario
    {
        $orders = new class () {
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
                throw new RuntimeException('Order cannot be placed');
            }
        };

        return new FlowScenario('synchronous handler failing', [$orders::class], [$orders], [], [
            FlowStage::when(function (FlowTestSupport $ecotone, array $metadata): void {
                try {
                    $ecotone->sendCommandWithRouting('order.place', '1', metadata: $metadata);
                } catch (RuntimeException) {
                }
            })
                ->expect(
                    ExpectedSpan::named('send command')->withStatus(SpanStatus::Error)->containing(
                        ExpectedSpan::named('process class@anonymous::place')->withStatus(SpanStatus::Error)->withAttributes([SpanAttribute::ENDPOINT_ID => 'order.placing']),
                    ),
                )
                ->withNoOtherSpans(),
        ]);
    }

    private static function asynchronousHandlerFailingIntoTheErrorChannel(): FlowScenario
    {
        $orders = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
                throw new RuntimeException('Order cannot be placed');
            }
        };

        return new FlowScenario('asynchronous handler failing into the error channel', [$orders::class], [$orders], [SimpleMessageChannelBuilder::createQueueChannel('orders'), ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('errorChannel')], [
            FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => $ecotone->sendCommandWithRouting('order.place', '1', metadata: $metadata))
                ->expect(
                    ExpectedSpan::named('send command')->withStatus(SpanStatus::Ok)->containing(
                        ExpectedSpan::named('send orders'),
                    ),
                )
                ->withNoOtherSpans(),
            FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false)))
                ->expect(
                    ExpectedSpan::named('receive orders')->withStatus(SpanStatus::Error)->containing(
                        ExpectedSpan::named('process class@anonymous::place')->withStatus(SpanStatus::Error),
                    ),
                ),
        ]);
    }
}
