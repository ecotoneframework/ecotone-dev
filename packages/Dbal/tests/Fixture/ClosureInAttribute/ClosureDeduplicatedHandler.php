<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ClosureInAttribute;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Attribute\Parameter\Header;
use Ecotone\Api\Attribute\QueryHandler;

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
