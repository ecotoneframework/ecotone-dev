<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Configuration;

use Ecotone\Messaging\ConfigurationVariableService;
use Tempest\Core\AppConfig;
use function Tempest\get;

/**
 * licence Apache-2.0
 */
final class TempestConfigurationVariableService implements ConfigurationVariableService
{
    private array $configCache = [];

    public function getByName(string $name): string|int|bool|array|null
    {
        if (isset($this->configCache[$name])) {
            return $this->configCache[$name];
        }

        $appConfig = get(AppConfig::class);

        // Handle specific Tempest config mappings
        if ($name === 'environment') {
            $this->configCache[$name] = $appConfig->environment->value;
            return $this->configCache[$name];
        }

        $value = $this->getNestedValue($appConfig, $name);

        if ($value !== null) {
            $this->configCache[$name] = $value;
            return $value;
        }

        // Fallback to environment variables
        $envValue = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        
        if ($envValue !== null) {
            $this->configCache[$name] = $this->convertValue($envValue);
            return $this->configCache[$name];
        }

        return null;
    }

    public function hasName(string $name): bool
    {
        return $this->getByName($name) !== null;
    }

    private function getNestedValue(object $config, string $path): mixed
    {
        $keys = explode('.', $path);
        $current = $config;

        foreach ($keys as $key) {
            if (is_object($current) && property_exists($current, $key)) {
                $current = $current->$key;
            } elseif (is_array($current) && array_key_exists($key, $current)) {
                $current = $current[$key];
            } else {
                return null;
            }
        }

        return $current;
    }

    private function convertValue(string $value): string|int|bool|array
    {
        // Convert string representations to proper types
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : $value;
        }

        return $value;
    }
}
