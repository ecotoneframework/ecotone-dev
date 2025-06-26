<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\RoutingTest;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;
use Test\Ecotone\Modelling\Fixture\NamedEvent\GuestWasAddedToBook;

class RoutingTestHandler
{
    public const ASYNC_CHANNEL = 'async';

    protected array $messages = [];

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function clearMessages(): void
    {
        $this->messages = [];
    }
}