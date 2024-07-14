<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow;

/**
 * licence Apache-2.0
 */
final class UserRegistered
{
    public function __construct(
        public string $userId
    ) {
    }
}
