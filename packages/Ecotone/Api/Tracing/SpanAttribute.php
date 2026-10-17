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
    public const TRACE_ROOT = 'ecotone.trace.root';
    public const CODE_FUNCTION_NAME = 'code.function.name';

    public const ECOTONE = 'ecotone';
    public const OPERATION_SEND = 'send';
    public const OPERATION_PROCESS = 'process';
}
