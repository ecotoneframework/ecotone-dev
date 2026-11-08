<?php

declare(strict_types=1);

namespace App\Consumer;

final readonly class ConsumerFeature
{
    public function __construct(
        public string $name,
        public string $value,
        public string $scope,
        public ?string $handler,
    ) {
    }

    /**
     * @param array<mixed> $feature
     */
    public static function fromSnapshot(array $feature): ?self
    {
        if (! is_string($feature['name'] ?? null) || ! is_string($feature['value'] ?? null)) {
            return null;
        }

        return new self(
            name: $feature['name'],
            value: $feature['value'],
            scope: is_string($feature['scope'] ?? null) ? $feature['scope'] : 'global',
            handler: is_string($feature['handler'] ?? null) ? $feature['handler'] : null,
        );
    }
}
