<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use Ecotone\Messaging\ConfigurationVariableService;

use function Tempest\env;

/**
 * licence Apache-2.0
 */
final class TempestConfigurationVariableService implements ConfigurationVariableService
{
    public function getByName(string $name): mixed
    {
        return env($name);
    }

    public function hasName(string $name): bool
    {
        return getenv($name) !== false;
    }
}
