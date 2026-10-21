<?php

declare(strict_types=1);

namespace App\Trace;

use Closure;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

final readonly class QueryStore
{
    private const LIFETIME_SECONDS = 86400;

    public function __construct(
        #[Autowire(service: 'pulse.cache')] private CacheItemPoolInterface $pool,
        #[Autowire(service: 'pulse.lock_factory')] private LockFactory $locks,
    ) {
    }

    public function read(string $service, string $queryKey): QueryRecord
    {
        $item = $this->pool->getItem($this->recordKey($service, $queryKey));

        return $item->isHit() ? QueryRecord::fromArray($item->get()) : QueryRecord::empty();
    }

    /**
     * @param Closure(QueryRecord): QueryRecord $change
     */
    public function update(string $service, string $queryKey, Closure $change): QueryRecord
    {
        $lock = $this->locks->createLock('pulse.query.' . $this->recordKey($service, $queryKey));
        $lock->acquire(true);

        try {
            $record = $change($this->read($service, $queryKey));
            $item = $this->pool->getItem($this->recordKey($service, $queryKey));
            $item->set($record->toArray())->expiresAfter(self::LIFETIME_SECONDS);
            $this->pool->save($item);
            if ($record->queryId !== null) {
                $pointer = $this->pool->getItem('query_id.' . $record->queryId);
                $pointer->set([$service, $queryKey])->expiresAfter(self::LIFETIME_SECONDS);
                $this->pool->save($pointer);
            }

            return $record;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{string, string}|null
     */
    public function whoAskedWith(string $queryId): ?array
    {
        $item = $this->pool->getItem('query_id.' . $queryId);

        return $item->isHit() ? $item->get() : null;
    }

    private function recordKey(string $service, string $queryKey): string
    {
        return 'query.' . sha1($service) . '.' . $queryKey;
    }
}
