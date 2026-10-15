<?php

declare(strict_types=1);

namespace App\Tempest;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class AppCommandHandler
{
    private bool $handled = false;

    #[CommandHandler('app.ping')]
    public function ping(): void
    {
        $this->handled = true;
    }

    #[QueryHandler('app.wasHandled')]
    public function wasHandled(): bool
    {
        return $this->handled;
    }
}
