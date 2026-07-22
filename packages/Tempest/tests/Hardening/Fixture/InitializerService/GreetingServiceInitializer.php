<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening\Fixture\InitializerService;

use Tempest\Container\Container;
use Tempest\Container\Initializer;

/**
 * Provides GreetingService the way Tempest provides Mailer — through an
 * Initializer, invisible to Container::has().
 *
 * licence Apache-2.0
 */
final class GreetingServiceInitializer implements Initializer
{
    public function initialize(Container $container): GreetingService
    {
        return new class implements GreetingService {
            public function greet(string $name): string
            {
                return 'Hello ' . $name;
            }
        };
    }
}
