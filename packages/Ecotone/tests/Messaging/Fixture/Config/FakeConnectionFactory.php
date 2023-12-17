<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Config;

use Enqueue\Null\NullContext;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;

final class FakeConnectionFactory implements ConnectionFactory
{
    public function __construct(private NullContext $nullContext)
    {

    }

    public function createContext(): Context
    {
        return $this->nullContext;
    }
}