<?php

declare(strict_types=1);

namespace App\Consumer;

final readonly class ConsumerSettings
{
    /**
     * @param list<ConsumerFeature> $serviceWide
     * @param array<string, list<ConsumerFeature>> $overrides
     */
    private function __construct(public array $serviceWide, private array $overrides)
    {
    }

    /**
     * @param list<Consumer> $consumers
     */
    public static function of(array $consumers): self
    {
        $values = [];
        foreach ($consumers as $consumer) {
            foreach ($consumer->features as $feature) {
                if ($feature->handler === null) {
                    $values[$feature->name][] = $feature->scope === 'global' ? 'global:' . $feature->value : 'consumer';
                }
            }
        }
        $sharedNames = array_keys(array_filter($values, static fn (array $occurrences): bool => count(array_unique($occurrences)) === 1 && str_starts_with($occurrences[0], 'global:')));

        $serviceWide = [];
        $overrides = [];
        foreach ($consumers as $consumer) {
            $overrides[$consumer->name] = [];
            foreach ($consumer->features as $feature) {
                if ($feature->handler === null && in_array($feature->name, $sharedNames, true)) {
                    $serviceWide[$feature->name] ??= $feature;
                } else {
                    $overrides[$consumer->name][] = $feature;
                }
            }
        }

        return new self(array_values($serviceWide), $overrides);
    }

    /**
     * @return list<ConsumerFeature>
     */
    public function overridesOf(Consumer $consumer): array
    {
        return $this->overrides[$consumer->name] ?? [];
    }
}
