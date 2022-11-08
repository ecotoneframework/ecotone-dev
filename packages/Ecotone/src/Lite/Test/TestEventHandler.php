<?php

declare(strict_types=1);

namespace Ecotone\Lite\Test;

use Ecotone\Messaging\Message;

final class TestEventHandler
{
    /** @var Message[] */
    private array $publishedEvents;
    /** @var Message[] */
    private array $sentCommands;
    /** @var Message[] */
    private array $sentEvents;

    public function subscribe(mixed $event): void
    {

    }


}