<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class Feature
{
    /**
     * @param array<string, string> $settings
     * @param list<string> $handlers
     */
    public function __construct(
        public string $name,
        public bool $enabled,
        public array $settings,
        public array $handlers,
        public string $hint,
        public string $configuration,
    ) {
    }

    public function headline(): ?string
    {
        $label = array_key_first($this->settings);
        if ($label === null) {
            return null;
        }
        $headline = (string) $label . ': ' . $this->settings[$label];

        return mb_strlen($headline) > 48 ? null : $headline;
    }

    /**
     * @param array<mixed> $feature
     */
    public static function fromSnapshot(array $feature): ?self
    {
        if (! is_string($feature['name'] ?? null) || ! is_bool($feature['enabled'] ?? null)) {
            return null;
        }

        return new self(
            name: $feature['name'],
            enabled: $feature['enabled'],
            settings: array_map(strval(...), array_filter(is_array($feature['settings'] ?? null) ? $feature['settings'] : [], is_scalar(...))),
            handlers: array_values(array_filter(is_array($feature['handlers'] ?? null) ? $feature['handlers'] : [], is_string(...))),
            hint: is_string($feature['hint'] ?? null) ? $feature['hint'] : '',
            configuration: is_string($feature['configuration'] ?? null) ? $feature['configuration'] : '',
        );
    }
}
