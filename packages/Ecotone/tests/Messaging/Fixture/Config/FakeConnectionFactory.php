<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Config;

use Enqueue\Null\NullContext;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;
use Test\Ecotone\Messaging\Fixture\Channel\FakeContextWithMessages;

final class FakeConnectionFactory implements ConnectionFactory
{
    public function __construct(
        private FakeContextWithMessages $context = new FakeContextWithMessages()
    )
    {

    }

    public function createContext(): Context
    {
        return $this->context;
    }
}