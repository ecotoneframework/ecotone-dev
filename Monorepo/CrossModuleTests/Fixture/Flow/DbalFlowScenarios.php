<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Fixture\Flow;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\ExtensionObject\OutboxForwardingMessageChannel;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ErrorHandlerConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\RetryTemplateBuilder;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Api\Tracing\SpanStatus;
use Ecotone\Test\Flow\ExpectedSpan;
use Ecotone\Test\Flow\FlowScenario;
use Ecotone\Test\Flow\FlowStage;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * licence Apache-2.0
 */
final class DbalFlowScenarios
{
    /**
     * @return list<FlowScenario>
     */
    public static function all(): array
    {
        return [
            self::commandConsumedFromADatabaseChannel(),
            self::failedMessageStoredInTheDeadLetterAndReplayed(),
            self::duplicatedCommandHandledOnce(),
            self::commandForwardedFromTheOutbox(),
            self::commandsOfTwoFlowsForwardedFromTheOutboxInOneHighThroughputBatch(),
        ];
    }

    private static function commandConsumedFromADatabaseChannel(): FlowScenario
    {
        $orders = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
            }
        };

        return new FlowScenario('command consumed from a database channel', [$orders::class], [$orders], [DbalBackedMessageChannelBuilder::create('orders')], [
            FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => $ecotone->sendCommandWithRouting('order.place', '1', metadata: $metadata))
                ->expect(ExpectedSpan::named('send command')->containing(ExpectedSpan::named('send orders')->ofKind(SpanKind::Producer)))
                ->withNoOtherSpans()
                ->leavingWorkPending(),
            FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup()))
                ->expect(
                    ExpectedSpan::named('send orders')->containing(
                        ExpectedSpan::named('receive orders')->ofKind(SpanKind::Consumer)->containing(
                            ExpectedSpan::named('process class@anonymous::place')->withAttributes([SpanAttribute::ENDPOINT_ID => 'order.placing']),
                        ),
                    ),
                ),
        ]);
    }

    private static function failedMessageStoredInTheDeadLetterAndReplayed(): FlowScenario
    {
        $orders = new class () {
            private int $attempts = 0;

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
                if (++$this->attempts <= 2) {
                    throw new RuntimeException('Payment provider unavailable');
                }
            }
        };

        return new FlowScenario('failed message stored in the dead letter and replayed', [$orders::class], [$orders], [
            DbalBackedMessageChannelBuilder::create('orders'),
            ErrorHandlerConfiguration::createWithDeadLetterChannel('errorChannel', RetryTemplateBuilder::fixedBackOff(0)->maxRetries(1), DbalDeadLetterBuilder::STORE_CHANNEL),
            InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
            ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('errorChannel'),
        ], [
            FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => $ecotone->sendCommandWithRouting('order.place', '1', metadata: $metadata))
                ->expect(ExpectedSpan::named('send command')->containing(ExpectedSpan::named('send orders')))
                ->withNoOtherSpans()
                ->leavingWorkPending(),
            FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false)))
                ->expect(
                    ExpectedSpan::named('receive orders')->withStatus(SpanStatus::Error)->containing(
                        ExpectedSpan::named('process class@anonymous::place')->withStatus(SpanStatus::Error, 'Payment provider unavailable'),
                        ExpectedSpan::named('send orders')->containing(
                            ExpectedSpan::named('receive orders')->withStatus(SpanStatus::Error)->containing(
                                ExpectedSpan::named('process class@anonymous::place')->withStatus(SpanStatus::Error),
                            ),
                        ),
                    ),
                ),
            FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->getGateway(DeadLetterGateway::class)->replayAll())
                ->leavingWorkPending(),
            FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup()))
                ->expect(
                    ExpectedSpan::named('receive orders')->withStatus(SpanStatus::Ok)->containing(
                        ExpectedSpan::named('process class@anonymous::place')->withStatus(SpanStatus::Ok)->withAttributes([SpanAttribute::ENDPOINT_ID => 'order.placing']),
                    ),
                ),
        ]);
    }

    private static function commandForwardedFromTheOutbox(): FlowScenario
    {
        $orders = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
            }
        };

        return new FlowScenario('command forwarded from the outbox', [$orders::class], [$orders], [
            OutboxForwardingMessageChannel::create('orders', DbalBackedMessageChannelBuilder::create('outbox'), 'order_processing'),
            DbalBackedMessageChannelBuilder::create('order_processing'),
        ], [
            FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => $ecotone->sendCommandWithRouting('order.place', '1', metadata: $metadata))
                ->expect(ExpectedSpan::named('send command')->containing(ExpectedSpan::named('send outbox')->ofKind(SpanKind::Producer)))
                ->withNoOtherSpans()
                ->leavingWorkPending(),
            FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->run('outbox', ExecutionPollingMetadata::createWithTestingSetup()))
                ->expect(
                    ExpectedSpan::named('send command')->asRoot()->containing(
                        ExpectedSpan::named('send outbox')->containing(ExpectedSpan::named('send order_processing')->ofKind(SpanKind::Producer)->relayedOutsideAUnitOfWork()),
                    ),
                )
                ->leavingWorkPending(),
            FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->run('order_processing', ExecutionPollingMetadata::createWithTestingSetup()))
                ->expect(
                    ExpectedSpan::named('send order_processing')->relayedOutsideAUnitOfWork()->containing(
                        ExpectedSpan::named('receive order_processing')->ofKind(SpanKind::Consumer)->containing(
                            ExpectedSpan::named('process class@anonymous::place')->withAttributes([SpanAttribute::ENDPOINT_ID => 'order.placing']),
                        ),
                    ),
                ),
        ]);
    }

    private static function commandsOfTwoFlowsForwardedFromTheOutboxInOneHighThroughputBatch(): FlowScenario
    {
        $orders = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
            }

            #[Asynchronous('orders')]
            #[CommandHandler('order.cancel', endpointId: 'order.cancelling')]
            public function cancel(string $orderId): void
            {
            }
        };
        $forwardedIn = fn (string $routingKey) => ExpectedSpan::named('send command')->asRoot()->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => $routingKey])->containing(
            ExpectedSpan::named('send outbox')->containing(ExpectedSpan::named('send order_processing')->ofKind(SpanKind::Producer)->relayedOutsideAUnitOfWork()),
        );
        $handledIn = fn (string $routingKey, string $method) => ExpectedSpan::named('send command')->asRoot()->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => $routingKey])->containing(
            ExpectedSpan::named('send outbox')->containing(
                ExpectedSpan::named('send order_processing')->relayedOutsideAUnitOfWork()->containing(
                    ExpectedSpan::named('receive order_processing')->containing(ExpectedSpan::named('process class@anonymous::' . $method)),
                ),
            ),
        );

        return new FlowScenario('commands of two flows forwarded from the outbox in one high-throughput batch', [$orders::class], [$orders], [
            OutboxForwardingMessageChannel::create('orders', DbalBackedMessageChannelBuilder::create('outbox'), 'order_processing'),
            DbalBackedMessageChannelBuilder::create('order_processing')->withHighThroughputPublishing(),
        ], [
            FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => $ecotone->sendCommandWithRouting('order.place', '1', metadata: $metadata))
                ->expect(ExpectedSpan::named('send command')->containing(ExpectedSpan::named('send outbox')))
                ->leavingWorkPending(),
            FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => $ecotone->sendCommandWithRouting('order.cancel', '1', metadata: $metadata))
                ->expect(ExpectedSpan::named('send command')->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'order.cancel'])->containing(ExpectedSpan::named('send outbox')))
                ->leavingWorkPending(),
            FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->run('outbox', ExecutionPollingMetadata::createWithTestingSetup()))
                ->expect($forwardedIn('order.place'), $forwardedIn('order.cancel'))
                ->leavingWorkPending(),
            FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->run('order_processing', ExecutionPollingMetadata::createWithTestingSetup()))
                ->expect($handledIn('order.place', 'place'), $handledIn('order.cancel', 'cancel')),
        ]);
    }

    private static function duplicatedCommandHandledOnce(): FlowScenario
    {
        $orders = new class () {
            #[Deduplicated]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(): void
            {
            }
        };
        $messageId = Uuid::v7()->toRfc4122();

        return new FlowScenario('duplicated command handled once', [$orders::class], [$orders], [], [
            FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => $ecotone->sendCommandWithRouting('order.place', metadata: [...$metadata, MessageHeaders::MESSAGE_ID => $messageId]))
                ->expect(ExpectedSpan::named('send command')->containing(ExpectedSpan::named('process class@anonymous::place')->withAttributes([SpanAttribute::ENDPOINT_ID => 'order.placing'])))
                ->withNoOtherSpans(),
            FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => $ecotone->sendCommandWithRouting('order.place', metadata: [...$metadata, MessageHeaders::MESSAGE_ID => $messageId]))
                ->expect(ExpectedSpan::named('send command')->asRoot()->withStatus(SpanStatus::Ok))
                ->withNoOtherSpans(),
        ]);
    }
}
