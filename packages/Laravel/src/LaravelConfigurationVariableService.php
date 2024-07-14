<?php

namespace Ecotone\Laravel;

use Ecotone\Messaging\ConfigurationVariableService;
use Illuminate\Support\Facades\Config;

/**
 * licence Apache-2.0
 */
class LaravelConfigurationVariableService implements ConfigurationVariableService
{
    public function getByName(string $name)
    {
        return Config::get($name);
    }

    public function hasName(string $name): bool
    {
        return Config::has($name);
    }
}
