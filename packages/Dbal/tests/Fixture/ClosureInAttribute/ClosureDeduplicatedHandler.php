<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ClosureInAttribute;

use Ecotone\Messaging\Attribute\Deduplicated;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class ClosureDeduplicatedHandler
{
    private int $called = 0;

    #[Deduplicated(expression: static function (#[Header('orderId')] string $orderId): string {
        return $orderId;
    })]
    #[CommandHandler('closureDedup.handle', endpointId: 'closureDedupEndpoint')]
    public function handle(): void
    {
        $this->called++;
    }

    #[QueryHandler('closureDedup.getCallCount')]
    public function getCallCount(): int
    {
        return $this->called;
    }
}
