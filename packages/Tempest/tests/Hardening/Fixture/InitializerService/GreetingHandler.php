<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening\Fixture\InitializerService;

use Ecotone\Modelling\Attribute\CommandHandler;

/**
 * licence Apache-2.0
 */
final class GreetingHandler
{
    #[CommandHandler('hardening.greet')]
    public function greet(string $name, GreetingService $greetingService): string
    {
        return $greetingService->greet($name);
    }
}
