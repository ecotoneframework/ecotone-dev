<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\MultiTenant;

use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;

final class FakeConnectionFactory implements ConnectionFactory
{
    private FakeContextWithMessages $context;

    public function __construct()
    {
        $this->context  = new FakeContextWithMessages();
    }

    public function createContext(): Context
    {
        return $this->context;
    }
}