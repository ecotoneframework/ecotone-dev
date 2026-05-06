<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\MultiTenant;

use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;
use LogicException;

/**
 * licence Enterprise
 */
final class FakeConnectionFactoryStub implements ConnectionFactory
{
    public function createContext(): Context
    {
        throw new LogicException('Tenant resolver test does not exercise downstream connection use.');
    }
}
