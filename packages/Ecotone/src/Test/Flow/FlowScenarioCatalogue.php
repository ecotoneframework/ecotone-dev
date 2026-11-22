<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow;

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
        foreach (self::runningOnThisPhp() as $scenario) {
            yield $scenario->name => [$scenario];
        }
    }

    /**
     * @return list<FlowScenario>
     */
    public static function runningOnThisPhp(): array
    {
        return array_values(array_filter(self::all(), fn (FlowScenario $scenario): bool => $scenario->runsOnThisPhp()));
    }

    /**
     * @return list<FlowScenario>
     */
    public static function leftOutOnThisPhp(): array
    {
        return array_values(array_filter(self::all(), fn (FlowScenario $scenario): bool => ! $scenario->runsOnThisPhp()));
    }

    /**
     * @return list<FlowScenario>
     */
    public static function all(): array
    {
        return [
            ...SynchronousFlowScenarios::all(),
            ...EventFlowScenarios::all(),
            ...AsynchronousFlowScenarios::all(),
            ...GatewayFlowScenarios::all(),
            ...ProjectionFlowScenarios::all(),
            ...DistributedFlowScenarios::all(),
            ...ModellingFlowScenarios::all(),
            ...InterceptorFlowScenarios::all(),
            ...FailureFlowScenarios::all(),
            ...ChannelFailureFlowScenarios::all(),
            ...TraceFlowScenarios::all(),
            ...RecoverabilityFlowScenarios::all(),
            ...ChannelFlowScenarios::all(),
            ...EndpointFlowScenarios::all(),
            ...RoutingFlowScenarios::all(),
            ...DynamicConsistencyBoundaryFlowScenarios::all(),
            ...CoroutineFlowScenarios::all(),
        ];
    }
}
