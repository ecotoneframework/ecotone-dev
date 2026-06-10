<?php

declare(strict_types=1);

namespace App\Tempest;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

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
