<?php

declare(strict_types=1);

namespace Ecotone\Api\Messaging\Flow;

/**
 * licence Apache-2.0
 */
enum FlowEdgeKind: string
{
    case ROUTED_TO = 'routedTo';
    case INPUT = 'input';
    case OUTPUT = 'output';
    case ASYNCHRONOUS = 'asynchronous';
    case SENDS_TO = 'sendsTo';
    case APPLIES = 'applies';
    case EMITS = 'emits';
    case CALLS = 'calls';
    case ROUTES_TO = 'routesTo';
}
