<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Orchestrator;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Test\Flow\Fixture\FlowApprovalWorkflow;
use Ecotone\Test\Flow\Fixture\FlowNotificationGateway;

/**
 * licence Apache-2.0
 */
final class GatewayFlowScenarios
{
    /**
     * @return list<FlowScenario>
     */
    public static function all(): array
    {
        return [
            self::messageGatewayStartingTheFlow(),
            self::orchestratorGatewayStartingTheFlow(),
            self::orchestratorReachedFromCommandHandler(),
        ];
    }

    private static function messageGatewayStartingTheFlow(): FlowScenario
    {
        $notifications = new class () {
            #[InternalHandler('notification.send', endpointId: 'notification.sending')]
            public function send(string $text): void
            {
            }
        };

        return new FlowScenario('message gateway starting the flow', [$notifications::class, FlowNotificationGateway::class], [$notifications], [], [
            FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->getGateway(FlowNotificationGateway::class)->send('hello'))
                ->expect(
                    ExpectedSpan::named('send notification.send')->ofKind(SpanKind::Server)->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'notification.send'])->containing(
                        ExpectedSpan::named('process class@anonymous::send')->withAttributes([SpanAttribute::ENDPOINT_ID => 'notification.sending']),
                    ),
                )
                ->withNoOtherSpans(),
        ]);
    }

    private static function orchestratorGatewayStartingTheFlow(): FlowScenario
    {
        $steps = self::approvalSteps();

        return new FlowScenario('orchestrator gateway starting the flow', [$steps::class, FlowApprovalWorkflow::class], [$steps], [], [
            FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->getGateway(FlowApprovalWorkflow::class)->start(['approval.validate', 'approval.notify'], 'request-1'))
                ->expect(
                    ExpectedSpan::named('send orchestrator')->ofKind(SpanKind::Server)->containing(
                        ExpectedSpan::named('process class@anonymous::validate')->withAttributes([SpanAttribute::ENDPOINT_ID => 'approval.validating']),
                        ExpectedSpan::named('process class@anonymous::notify')->withAttributes([SpanAttribute::ENDPOINT_ID => 'approval.notifying']),
                    ),
                )
                ->withNoOtherSpans(),
        ]);
    }

    private static function orchestratorReachedFromCommandHandler(): FlowScenario
    {
        $approvals = new class () {
            #[CommandHandler('approval.request', outputChannelName: 'approval.workflow', endpointId: 'approval.requesting')]
            public function request(string $request): string
            {
                return $request;
            }

            #[Orchestrator('approval.workflow')]
            public function workflow(): array
            {
                return ['approval.validate', 'approval.notify'];
            }
        };
        $steps = self::approvalSteps();

        return new FlowScenario('orchestrator reached from a command handler', [$approvals::class, $steps::class], [$approvals, $steps], [], [
            FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => $ecotone->sendCommandWithRouting('approval.request', 'request-1', metadata: $metadata))
                ->expect(
                    ExpectedSpan::named('send command')->withAttributes([SpanAttribute::MESSAGING_DESTINATION_NAME => 'approval.request'])->containing(
                        ExpectedSpan::named('process class@anonymous::request')->withAttributes([SpanAttribute::ENDPOINT_ID => 'approval.requesting']),
                        ExpectedSpan::named('process class@anonymous::validate')->withAttributes([SpanAttribute::ENDPOINT_ID => 'approval.validating']),
                        ExpectedSpan::named('process class@anonymous::notify')->withAttributes([SpanAttribute::ENDPOINT_ID => 'approval.notifying']),
                    ),
                )
                ->withNoOtherSpans(),
        ]);
    }

    private static function approvalSteps(): object
    {
        return new class () {
            #[InternalHandler('approval.validate', endpointId: 'approval.validating')]
            public function validate(string $request): string
            {
                return $request;
            }

            #[InternalHandler('approval.notify', endpointId: 'approval.notifying')]
            public function notify(string $request): string
            {
                return $request;
            }
        };
    }
}
