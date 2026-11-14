<?php

declare(strict_types=1);

namespace App\Snapshot;

use ArrayAccess;
use Closure;
use LogicException;

/**
 * @implements ArrayAccess<string, mixed>
 */
final class SectionedSnapshot implements ArrayAccess
{
    /**
     * @var array<string, mixed>
     */
    private array $read = [];

    /**
     * @param array<string, Closure(): mixed> $sections
     */
    public function __construct(private readonly array $sections)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->offsetGet($offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (! is_string($offset) || ! isset($this->sections[$offset])) {
            return null;
        }
        if (! array_key_exists($offset, $this->read)) {
            $this->read[$offset] = ($this->sections[$offset])();
        }

        return $this->read[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('A snapshot is read as the service reported it and is never changed in place. Store a reply of the service through SnapshotStore::receive() instead.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('A snapshot is read as the service reported it and is never changed in place. Store a reply of the service through SnapshotStore::receive() instead.');
    }

    public function mergedWithTenant(self $tenant): self
    {
        $merged = $this->sections;
        foreach (array_keys($tenant->sections) as $name) {
            $merged[$name] = function () use ($tenant, $name): mixed {
                $ofService = $this[$name];
                $ofTenant = $tenant[$name];

                return is_array($ofService) && is_array($ofTenant) ? array_replace_recursive($ofService, $ofTenant) : $ofTenant;
            };
        }
        $merged['channels'] = function () use ($tenant): array {
            $measured = [];
            foreach (is_array($tenant['channels']) ? $tenant['channels'] : [] as $channel) {
                if (is_array($channel) && is_string($channel['name'] ?? null)) {
                    $measured[$channel['name']] = $channel + ['perTenant' => true];
                }
            }

            return array_map(static fn (mixed $channel): mixed => is_array($channel) ? ($measured[$channel['name'] ?? ''] ?? $channel) : $channel, is_array($this['channels']) ? $this['channels'] : []);
        };

        return new self($merged);
    }
}
