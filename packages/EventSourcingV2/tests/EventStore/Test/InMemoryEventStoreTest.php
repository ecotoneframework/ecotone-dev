<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\EventStore\Test;

use Ecotone\EventSourcingV2\EventStore\EventStore;
use Ecotone\EventSourcingV2\EventStore\Test\InMemoryEventStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\EventSourcingV2\EventStore\EventStoreTestCaseTrait;

#[CoversClass(InMemoryEventStore::class)]
class InMemoryEventStoreTest extends TestCase
{
    use EventStoreTestCaseTrait;
    
    protected function createEventStore(): EventStore
    {
        return new InMemoryEventStore();
    }
}
