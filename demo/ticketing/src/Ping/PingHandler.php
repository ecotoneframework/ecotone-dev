<?php

declare(strict_types=1);

namespace App\Ping;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;

final class PingHandler
{
    #[Distributed]
    #[CommandHandler('demo.ping')]
    public function ping(string $sender): string
    {
        return sprintf('pong from ticketing to %s', $sender);
    }
}
