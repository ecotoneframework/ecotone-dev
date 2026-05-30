<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\AsyncQueue;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;

/**
 * licence Apache-2.0
 */
final class NotifyCommandHandler
{
    public bool $handled = false;

    #[Asynchronous('ecotone_test_queue')]
    #[CommandHandler('notify', endpointId: 'notify_handler')]
    public function handle(NotifyCommand $command): void
    {
        $this->handled = true;
    }
}
