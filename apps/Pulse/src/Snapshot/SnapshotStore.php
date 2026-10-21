<?php

declare(strict_types=1);

namespace App\Snapshot;

use Closure;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

final readonly class SnapshotStore
{
    public function __construct(
        #[Autowire(service: 'pulse.cache')] private CacheItemPoolInterface $pool,
        #[Autowire(service: 'pulse.lock_factory')] private LockFactory $locks,
    ) {
    }

    public function read(string $service): ServiceRecord
    {
        $item = $this->pool->getItem('service.' . $service);

        return $item->isHit() ? ServiceRecord::fromArray($item->get()) : ServiceRecord::empty();
    }

    /**
     * @param Closure(ServiceRecord): ServiceRecord $change
     */
    public function update(string $service, Closure $change): ServiceRecord
    {
        $lock = $this->locks->createLock('pulse.service.' . $service);
        $lock->acquire(true);

        try {
            $record = $change($this->read($service));
            $item = $this->pool->getItem('service.' . $service);
            $item->set($record->toArray());
            $this->pool->save($item);

            return $record;
        } finally {
            $lock->release();
        }
    }
}
