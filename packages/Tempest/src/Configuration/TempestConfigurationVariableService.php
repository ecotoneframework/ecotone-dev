<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Configuration;

use Ecotone\Messaging\ConfigurationVariableService;

/**
 * licence Apache-2.0
 */
final class TempestConfigurationVariableService implements ConfigurationVariableService
{
    public function getByName(string $name): string|int|bool|array|null
    {
        return \Tempest\env($name);
    }

    public function hasName(string $name): bool
    {
        return \Tempest\env($name) !== null;
    }
}
