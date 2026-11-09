<?php

declare(strict_types=1);

namespace App\Consumer;

use App\Snapshot\ServiceSnapshot;

final readonly class Consumer
{
    /**
     * @param list<string> $handlers
     * @param list<ConsumerFeature> $features
     */
    public function __construct(
        public string $name,
        public string $kind,
        public array $handlers,
        public array $features,
    ) {
    }

    /**
     * @return list<self>
     */
    public static function allOf(ServiceSnapshot $service): array
    {
        $consumers = [];
        foreach (is_array($service->snapshot['consumers'] ?? null) ? $service->snapshot['consumers'] : [] as $consumer) {
            if (! is_array($consumer) || ! is_string($consumer['name'] ?? null)) {
                continue;
            }
            $consumers[] = new self(
                name: $consumer['name'],
                kind: ($consumer['kind'] ?? null) === 'inbound' ? 'inbound' : 'polling',
                handlers: array_values(array_filter(is_array($consumer['handlers'] ?? null) ? $consumer['handlers'] : [], is_string(...))),
                features: array_values(array_filter(array_map(static fn (mixed $feature): ?ConsumerFeature => is_array($feature) ? ConsumerFeature::fromSnapshot($feature) : null, is_array($consumer['features'] ?? null) ? $consumer['features'] : []))),
            );
        }

        return $consumers;
    }
}
