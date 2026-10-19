<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanKind;

/**
 * licence Apache-2.0
 */
final class DistributedFlowScenarios
{
    /**
     * @return list<FlowScenario>
     */
    public static function all(): array
    {
        return [
            self::distributedCommandConsumedByTheTargetService(),
        ];
    }

    private static function distributedCommandConsumedByTheTargetService(): FlowScenario
    {
        $tickets = new class () {
            #[Distributed]
            #[CommandHandler('ticket.create', endpointId: 'ticket.creation')]
            public function create(string $description): void
            {
            }
        };

        return new FlowScenario(
            'distributed command consumed by the target service',
            [$tickets::class],
            [$tickets],
            [
                SimpleMessageChannelBuilder::createQueueChannel('distributed_channel'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'distributed_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ticket_service'),
            ],
            [
                FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => $ecotone->getDistributedBus()->sendCommand('ticket_service', 'ticket.create', 'User changed billing address', metadata: $metadata))
                    ->expect(
                        ExpectedSpan::named('send distributed')->ofKind(SpanKind::Producer)->containing(
                            ExpectedSpan::named('send distributed_channel')->ofKind(SpanKind::Producer),
                        ),
                    )
                    ->expectNoSpanNamed('process class@anonymous::create')
                    ->withNoOtherSpans(),
                FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->run('distributed_channel', ExecutionPollingMetadata::createWithTestingSetup()))
                    ->expect(
                        ExpectedSpan::named('receive distributed_channel')->ofKind(SpanKind::Consumer)->containing(
                            ExpectedSpan::named('send command')->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'ticket.create'])->containing(
                                ExpectedSpan::named('process class@anonymous::create')->withAttributes([SpanAttribute::ENDPOINT_ID => 'ticket.creation']),
                            ),
                        ),
                    )
                    ->withNoOtherSpans(),
            ],
        );
    }
}
