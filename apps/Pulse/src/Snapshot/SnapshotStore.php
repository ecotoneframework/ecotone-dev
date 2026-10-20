<?php

declare(strict_types=1);

namespace App\Snapshot;

use DateTimeImmutable;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class SnapshotStore
{
    public function __construct(#[Autowire(service: 'pulse.cache')] private CacheItemPoolInterface $pool)
    {
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function storeSnapshot(string $service, array $snapshot, DateTimeImmutable $receivedAt): void
    {
        $this->write('snapshot.' . $service, ['receivedAt' => $receivedAt->format(DATE_ATOM), 'snapshot' => $snapshot]);
    }

    public function storeRequest(string $service, DateTimeImmutable $requestedAt): void
    {
        $this->write('request.' . $service, ['requestedAt' => $requestedAt->format(DATE_ATOM)]);
    }

    /**
     * @return array{receivedAt: DateTimeImmutable, snapshot: array<string, mixed>}|null
     */
    public function snapshotOf(string $service): ?array
    {
        $stored = $this->read('snapshot.' . $service);

        return $stored === null ? null : ['receivedAt' => new DateTimeImmutable($stored['receivedAt']), 'snapshot' => $stored['snapshot']];
    }

    public function requestedAt(string $service): ?DateTimeImmutable
    {
        $stored = $this->read('request.' . $service);

        return $stored === null ? null : new DateTimeImmutable($stored['requestedAt']);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function write(string $key, array $value): void
    {
        $item = $this->pool->getItem($key);
        $item->set($value);
        $this->pool->save($item);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $key): ?array
    {
        $item = $this->pool->getItem($key);

        return $item->isHit() ? $item->get() : null;
    }
}
