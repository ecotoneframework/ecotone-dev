<?php

declare(strict_types=1);

namespace Ecotone\Api\Tracing;

/**
 * licence Apache-2.0
 */
final class SpanAttribute
{
    public const MESSAGING_SYSTEM = 'messaging.system';
    public const MESSAGING_OPERATION_TYPE = 'messaging.operation.type';
    public const MESSAGING_DESTINATION_NAME = 'messaging.destination.name';
    public const ENDPOINT_ID = 'ecotone.endpoint.id';
    public const PROJECTION_NAME = 'ecotone.projection.name';
    public const PROJECTION_OPERATION = 'ecotone.projection.operation';
    public const TRACE_ROOT = 'ecotone.trace.root';
    public const TRACE_CARRIED = 'ecotone.trace.carried';
    public const TRACE_UNTRACKED = 'ecotone.trace.untracked';
    public const TRACE_FAILED_ATTEMPT = 'ecotone.trace.failed_attempt';
    public const CODE_FUNCTION_NAME = 'code.function.name';
    public const CHANNEL_NAME = 'ecotone.channel.name';
    public const BATCH_DELIVERIES = 'ecotone.batch.deliveries';
    public const ROUTE_UNDELIVERED = 'ecotone.route.undelivered';
    public const ROUTE_COLLECTED = 'ecotone.route.collected';
    public const EXECUTION_STEP = 'ecotone.execution.step';
    public const EXECUTION_OUTCOME = 'ecotone.execution.outcome';
    public const EXECUTION_PARENT = 'ecotone.execution.parent';
    public const EXECUTION_ORDER = 'ecotone.execution.order';
    public const EXECUTION_PROCESS = 'ecotone.execution.process';
    public const EXECUTION_HINTS = 'ecotone.execution.hints';
    public const EXECUTION_SENT_MESSAGE = 'ecotone.execution.sent_message';

    public const ECOTONE = 'ecotone';
    public const OPERATION_SEND = 'send';
    public const OPERATION_PROCESS = 'process';
}
