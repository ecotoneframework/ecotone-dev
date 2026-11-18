<?php

declare(strict_types=1);

namespace Ecotone\Api\Tracing;

/**
 * licence Apache-2.0
 */
enum TracingLevel: string
{
    case Application = 'application';
    case Framework = 'framework';
}
