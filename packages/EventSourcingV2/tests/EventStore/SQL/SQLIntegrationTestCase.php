<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\EventStore\SQL;

use Ecotone\EventSourcingV2\EventStore\EventStore;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\EventSourcingV2\EventStore\CatchupProjectionTransactionalTestTrait;
use Test\Ecotone\EventSourcingV2\EventStore\EventLoaderTestCaseTrait;
use Test\Ecotone\EventSourcingV2\EventStore\EventStoreTestCaseTrait;
use Test\Ecotone\EventSourcingV2\EventStore\SQL\Helpers\DatabaseConfig;
use Test\Ecotone\EventSourcingV2\EventStore\SubscriptionTransactionalTestCaseTrait;

abstract class SQLIntegrationTestCase extends TestCase
{
    use EventStoreTestCaseTrait;
    use EventLoaderTestCaseTrait;
    use CatchupProjectionTransactionalTestTrait;
    use SubscriptionTransactionalTestCaseTrait;

    abstract protected static function config(): DatabaseConfig;

    protected static function createEventStore(...$args): EventStore
    {
        return static::config()->createEventStore(...$args);
    }
}