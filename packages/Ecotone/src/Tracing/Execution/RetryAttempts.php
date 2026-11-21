<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

/**
 * licence Apache-2.0
 */
final class RetryAttempts
{
    public int $made = 0;
    public ?string $lastFailure = null;
}
