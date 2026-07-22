<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening\Fixture\BootValidation;

use RuntimeException;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

/**
 * Tempest factory for MissingServiceContract. Boot validation must accept the
 * registration WITHOUT invoking it — resolving here would be the equivalent of
 * opening a database connection at compile time.
 *
 * licence Apache-2.0
 */
final class ExplodingFactoryInitializer implements Initializer
{
    public static bool $invoked = false;

    public function initialize(Container $container): MissingServiceContract
    {
        self::$invoked = true;

        throw new RuntimeException('The factory must not be invoked during boot validation');
    }
}
