<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening\Fixture\CachePing;

use Ecotone\Modelling\Attribute\CommandHandler;

/**
 * licence Apache-2.0
 */
final class CachePingHandler
{
    #[CommandHandler('hardening.cache.ping')]
    public function ping(): string
    {
        return 'pong';
    }
}
