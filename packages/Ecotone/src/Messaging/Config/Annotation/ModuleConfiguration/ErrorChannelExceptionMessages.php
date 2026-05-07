<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class ErrorChannelExceptionMessages
{
    public static function delayedRetryOnInboundChannelAdapter(string $className, string $methodName): string
    {
        return "#[DelayedRetry] cannot be used on an Inbound Channel Adapter `{$className}::{$methodName}`. "
            . 'Inbound Channel Adapters consume from external systems (Kafka, AMQP, scheduled tasks) and have no source Message Channel for the framework to reschedule a delayed retry into. '
            . 'Use #[ErrorChannel] to capture the failure for later replay (e.g. from a Dead Letter), and optionally combine it with #[InstantRetry] for in-process retries before forwarding to the Error Channel.';
    }

    public static function errorChannelDirectlyOnAsyncHandlerMethod(string $endpointId): string
    {
        return "Asynchronous handler `{$endpointId}` has `#[ErrorChannel]` placed directly on the handler method — this has no effect on async handlers. "
            . "Pass it via the #[Asynchronous] attribute instead: `#[Asynchronous('channel', asynchronousExecution: [new ErrorChannel('...')])]` so the polling consumer routes failures correctly.";
    }

    public static function delayedRetryDirectlyOnAsyncHandlerMethod(string $endpointId): string
    {
        return "Asynchronous handler `{$endpointId}` has `#[DelayedRetry]` placed directly on the handler method — this has no effect on async handlers. "
            . "Pass it via the #[Asynchronous] attribute instead: `#[Asynchronous('channel', asynchronousExecution: [new DelayedRetry(...)])]` so the polling consumer applies the retry policy correctly.";
    }

    public static function errorChannelAndDelayedRetryMutuallyExclusiveOnHandler(string $endpointId): string
    {
        return "Handler `{$endpointId}` declares both #[ErrorChannel] and #[DelayedRetry] in #[Asynchronous] asynchronousExecution — these are mutually exclusive. "
            . 'Use #[ErrorChannel] to send failures to a channel you control, OR #[DelayedRetry] to have Ecotone manage the retry+dead-letter flow with a generated channel.';
    }

    public static function errorChannelAndDelayedRetryMutuallyExclusiveOnGateway(string $gatewayInterfaceFqn): string
    {
        return "Gateway `{$gatewayInterfaceFqn}` declares both #[ErrorChannel] and #[DelayedRetry] — these are mutually exclusive. "
            . 'Use #[ErrorChannel] to send failures to a channel you control, OR #[DelayedRetry] to have Ecotone manage the retry+dead-letter flow with a generated channel.';
    }
}
