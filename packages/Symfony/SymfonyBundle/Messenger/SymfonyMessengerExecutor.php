<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Messenger;

use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Gateway\QueryBus;

/**
 * licence Apache-2.0
 */
final class SymfonyMessengerExecutor
{
    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private EventBus $eventBus
    ) {

    }

    public function query(object $query): mixed
    {
        return $this->queryBus->send($query);
    }

    public function command(object $command): mixed
    {
        return $this->commandBus->send($command);
    }

    public function event(object $event): void
    {
        $this->eventBus->publish($event);
    }
}
