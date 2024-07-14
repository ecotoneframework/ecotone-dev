<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\MultiTenant;

use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;

/**
 * licence Apache-2.0
 */
final class FakeConnectionFactory implements ConnectionFactory
{
    public function __construct(private ?FakeContextWithMessages $context = null)
    {
        $this->context ??= new FakeContextWithMessages();
    }

    public function createContext(): Context
    {
        return $this->context;
    }
}
