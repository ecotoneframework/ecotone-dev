<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow;

final class UserRegistered
{
    public function __construct(
        public string $userId
    ) {
    }
}
