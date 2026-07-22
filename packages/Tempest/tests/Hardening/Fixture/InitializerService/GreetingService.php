<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening\Fixture\InitializerService;

/**
 * licence Apache-2.0
 */
interface GreetingService
{
    public function greet(string $name): string;
}
