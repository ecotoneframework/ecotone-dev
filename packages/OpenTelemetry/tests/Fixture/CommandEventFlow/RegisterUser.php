<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow;

final class RegisterUser
{
    public function __construct(public string $userId)
    {
    }
}
