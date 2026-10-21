<?php

declare(strict_types=1);

namespace App\Graph;

final readonly class ServiceScopeFilter
{
    /**
     * @param list<string> $available
     * @param list<string> $neighbours
     */
    private function __construct(public string $selected, public array $available, public array $neighbours)
    {
    }

    /**
     * @param list<ServiceFlow> $flows
     */
    public static function of(array $flows, string $requested): self
    {
        $available = array_map(static fn (ServiceFlow $flow): string => $flow->name, $flows);
        sort($available);
        if ($requested === '') {
            return new self('', $available, []);
        }
        if (! in_array($requested, $available, true)) {
            throw new UnknownServiceFilter($requested, $available);
        }

        $neighbours = [];
        foreach (DistributedLinks::between($flows) as $link) {
            if ($link->fromService === $requested) {
                $neighbours[] = $link->toService;
            }
            if ($link->toService === $requested) {
                $neighbours[] = $link->fromService;
            }
        }
        foreach ($flows as $flow) {
            $targets = array_keys($flow->commandMappings());
            if ($flow->name === $requested) {
                array_push($neighbours, ...$targets);
            } elseif (in_array($requested, $targets, true)) {
                $neighbours[] = $flow->name;
            }
        }
        $neighbours = array_values(array_intersect($available, array_diff(array_unique($neighbours), [$requested])));

        return new self($requested, $available, $neighbours);
    }

    public function isNarrowed(): bool
    {
        return $this->selected !== '';
    }

    /**
     * @return list<string>
     */
    public function visible(): array
    {
        return $this->isNarrowed() ? array_values(array_intersect($this->available, [$this->selected, ...$this->neighbours])) : $this->available;
    }

    public function isGreyed(string $service): bool
    {
        return $this->isNarrowed() && $service !== $this->selected;
    }

    public function involves(string ...$services): bool
    {
        return ! $this->isNarrowed() || in_array($this->selected, $services, true);
    }
}
